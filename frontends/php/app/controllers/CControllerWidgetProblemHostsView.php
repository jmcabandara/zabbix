<?php
/*
** Zabbix
** Copyright (C) 2001-2018 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/../../include/blocks.inc.php';
require_once dirname(__FILE__).'/../../include/hostgroups.inc.php';

class CControllerWidgetProblemHostsView extends CControllerWidget {

	public function __construct() {
		parent::__construct();

		$this->setType(WIDGET_PROBLEM_HOSTS);
		$this->setValidationRules([
			'name' => 'string',
			'fields' => 'json'
		]);
	}

	protected function doAction() {
		$fields = $this->getForm()->getFieldsData();

		$config = select_config();

		$filter_groupids = $fields['groupids'] ? getSubGroups($fields['groupids']) : null;
		$filter_hostids = $fields['hostids'] ? $fields['hostids'] : null;
		$filter_problem = ($fields['problem'] !== '') ? $fields['problem'] : null;
		$filter_severities = $fields['severities']
			? $fields['severities']
			: range(TRIGGER_SEVERITY_NOT_CLASSIFIED, TRIGGER_SEVERITY_COUNT - 1);
		$filter_show_suppressed = $fields['show_suppressed'];
		$filter_ext_ack = $fields['ext_ack'];

		if ($fields['exclude_groupids']) {
			$exclude_groupids = getSubGroups($fields['exclude_groupids']);

			if ($filter_hostids === null) {
				// Get all groups if no selected groups defined.
				if ($filter_groupids === null) {
					$filter_groupids = array_keys(API::HostGroup()->get([
						'output' => [],
						'preservekeys' => true
					]));
				}

				$filter_groupids = array_diff($filter_groupids, $exclude_groupids);

				// Get available hosts.
				$filter_hostids = array_keys(API::Host()->get([
					'output' => [],
					'groupids' => $filter_groupids,
					'preservekeys' => true
				]));
			}

			$exclude_hostids = array_keys(API::Host()->get([
				'output' => [],
				'groupids' => $exclude_groupids,
				'preservekeys' => true
			]));

			$filter_hostids = array_diff($filter_hostids, $exclude_hostids);
		}

		// Get host groups.
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'groupids' => $filter_groupids,
			'hostids' => $filter_hostids,
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		// Get hosts.
		$hosts = API::Host()->get([
			'output' => ['hostid', 'name', 'maintenanceid', 'maintenance_status', 'maintenance_type'],
			'selectGroups' => ['groupid'],
			'groupids' => array_keys($groups),
			'hostids' => $filter_hostids,
			'filter' => [
				'maintenance_status' => null
			],
			'monitored_hosts' => true,
			'preservekeys' => true
		]);

		// Get triggers.
		$triggers = API::Trigger()->get([
			'output' => [],
			'selectHosts' => ['hostid'],
			'hostids' => array_keys($hosts),
			'groupids' => array_keys($groups),
			'filter' => [
				'value' => TRIGGER_VALUE_TRUE
			],
			'monitored' => true,
			'preservekeys' => true
		]);

		// Add default values for each host group and count hosts inside.
		foreach ($hosts as $host) {
			foreach ($host['groups'] as $group) {
				if (!array_key_exists($group['groupid'], $groups)) {
					continue;
				}

				if (!array_key_exists('hosts_total_count', $groups[$group['groupid']])) {
					$groups[$group['groupid']]['highest_severity'] = TRIGGER_SEVERITY_NOT_CLASSIFIED;
					$groups[$group['groupid']]['hosts_total_count'] = 0;
					$groups[$group['groupid']]['hosts_problematic_unack_count'] = 0;
					$groups[$group['groupid']]['hosts_problematic_unack_list'] = [];

					if ($filter_ext_ack != EXTACK_OPTION_UNACK) {
						$groups[$group['groupid']]['hosts_problematic_count'] = 0;
						$groups[$group['groupid']]['hosts_problematic_list'] = [];
					}
				}

				$groups[$group['groupid']]['hosts_total_count']++;
			}
		}

		// Get problems.
		$problems = API::Problem()->get([
			'output' => ['objectid', 'acknowledged', 'severity'],
			'groupids' => array_keys($groups),
			'hostids' => array_keys($hosts),
			'objectids' => array_keys($triggers),
			'search' => [
				'name' => $filter_problem
			],
			'severities' => $filter_severities,
			'acknowledged' => ($filter_ext_ack == EXTACK_OPTION_UNACK) ? false : null,
			'suppressed' => ($filter_show_suppressed == ZBX_PROBLEM_SUPPRESSED_FALSE) ? false : null
		]);

		$hosts_data = [];

		// Process problems.
		foreach ($problems as $problem) {
			$host = $hosts[$triggers[$problem['objectid']]['hosts'][0]['hostid']];

			// Prepare hosts data for tables displayed in hintboxes.
			if (!array_key_exists($host['hostid'], $hosts_data)) {
				$hosts_data[$host['hostid']] = [
					'host' => $host['name'],
					'hostid' => $host['hostid'],
					'maintenanceid' => $host['maintenanceid'],
					'maintenance_status' => $host['maintenance_status'],
					'maintenance_type' => $host['maintenance_type'],
					'severities' => array_fill_keys($filter_severities, 0)
				];
			}

			// Count number of host problems per severity.
			$hosts_data[$host['hostid']]['severities'][$problem['severity']]++;

			// Propagate problem to all host groups in which host is added.
			foreach ($host['groups'] as $group) {
				$groupid = $group['groupid'];

				if (!array_key_exists($groupid, $groups)) {
					continue;
				}

				// Searches for the highest severity set for filtered problems in particular host group.
				if ($problem['severity'] > $groups[$groupid]['highest_severity']) {
					$groups[$groupid]['highest_severity'] = $problem['severity'];
				}

				/**
				 * Counts:
				 *  - problematic hosts (hosts with events in 'problem' state);
				 *  - unacknowledged problematic hosts (hosts with unacknowledged events in 'problem' state).
				 *
				 * Creates a list of problematic hosts and unacknowledged problematic hosts for each host group.
				 *
				 * Each host need to be counted only one time in each host group.
				 * Host name is added for sorting.
				 */
				if ($filter_ext_ack != EXTACK_OPTION_UNACK
						&& !array_key_exists($host['hostid'], $groups[$groupid]['hosts_problematic_list'])) {
					$groups[$groupid]['hosts_problematic_list'][$host['hostid']]['name'] = $host['name'];
					$groups[$groupid]['hosts_problematic_count']++;
				}

				if ($problem['acknowledged'] == EVENT_NOT_ACKNOWLEDGED
						&& !array_key_exists($host['hostid'], $groups[$groupid]['hosts_problematic_unack_list'])) {
					$groups[$groupid]['hosts_problematic_unack_list'][$host['hostid']]['name'] = $host['name'];
					$groups[$groupid]['hosts_problematic_unack_count']++;
				}
			}
		}

		// Sort results.
		CArrayHelper::sort($groups, ['name']);

		foreach ($groups as &$group) {
			if ($filter_ext_ack != EXTACK_OPTION_UNACK) {
				CArrayHelper::sort($group['hosts_problematic_list'], ['name']);
			}

			CArrayHelper::sort($group['hosts_problematic_unack_list'], ['name']);
		}
		unset($group);

		// Pass results to view.
		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->getDefaultHeader()),
			'filter' => [
				'hostids' => $fields['hostids'],
				'problem' => $fields['problem'],
				'severities' => $filter_severities,
				'show_suppressed' => $fields['show_suppressed'],
				'hide_empty_groups' => $fields['hide_empty_groups'],
				'ext_ack' => $fields['ext_ack']
			],
			'config' => [
				'severity_name_0' => $config['severity_name_0'],
				'severity_name_1' => $config['severity_name_1'],
				'severity_name_2' => $config['severity_name_2'],
				'severity_name_3' => $config['severity_name_3'],
				'severity_name_4' => $config['severity_name_4'],
				'severity_name_5' => $config['severity_name_5']
			],
			'hosts_data' => $hosts_data,
			'groups' => $groups,
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		]));
	}
}
