<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class virtualhistory extends eqLogic {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	public static function event() {
		$cmd = virtualhistoryCmd::byId(init('id'));
		if (!is_object($cmd) || $cmd->getEqType() != 'virtualhistory') {
			throw new Exception(__('Commande ID virtuel inconnu, ou la commande n\'est pas de type virtuel : ', __FILE__) . init('id'));
		}
		$cmd->event(init('value'),init('datetime'));
	}

	public static function cron() {
		foreach (eqLogic::byType('virtualhistory', true) as $eqLogic) {
			$autorefresh = $eqLogic->getConfiguration('autorefresh');
			if ($autorefresh != '') {
				try {
					$c = new Cron\CronExpression($autorefresh, new Cron\FieldFactory);
					if ($c->isDue()) {
						$eqLogic->refresh();
					}
				} catch (Exception $exc) {
					log::add('virtualhistory', 'error', __('Expression cron non valide pour ', __FILE__) . $eqLogic->getHumanName() . ' : ' . $autorefresh);
				}
			}
		}
	}

	/*     * *********************Methode d'instance************************* */
	public function refresh() {
		try {
			foreach ($this->getCmd('info') as $cmd) {
				if ($cmd->getConfiguration('calcul') == '' || $cmd->getConfiguration('virtualhistoryAction', 0) != '0') {
					continue;
				}
				$value = $cmd->execute();
				if ($cmd->execCmd() != $cmd->formatValue($value)) {
					$cmd->setCollectDate('');
					$cmd->event($value);
				}
			}
		} catch (Exception $exc) {
			log::add('virtualhistory', 'error', __('Erreur pour ', __FILE__) . $eqLogic->getHumanName() . ' : ' . $exc->getMessage());
		}
	}

	public function postSave() {
		$refresh = $this->getCmd(null, 'refresh');
		if (!is_object($refresh)) {
			$refresh = new virtualhistoryCmd();
			$refresh->setLogicalId('refresh');
			$refresh->setIsVisible(1);
			$refresh->setName(__('Rafraichir', __FILE__));
		}
		$refresh->setType('action');
		$refresh->setSubType('other');
		$refresh->setEqLogic_id($this->getId());
		$refresh->save();
	}

	public function copyFromEqLogic($_eqLogic_id) {
		$eqLogic = eqLogic::byId($_eqLogic_id);
		if (!is_object($eqLogic)) {
			throw new Exception(__('Impossible de trouver l\'équipement : ', __FILE__) . $_eqLogic_id);
		}
		if ($eqLogic->getEqType_name() == 'virtualhistory') {
			throw new Exception(__('Vous ne pouvez importer la configuration d\'un équipement virtuel', __FILE__));
		}
		foreach ($eqLogic->getCategory() as $key => $value) {
			$this->setCategory($key, $value);
		}
		foreach ($eqLogic->getCmd() as $cmd_def) {
			$cmd_name = $cmd_def->getName();
			if ($cmd_name == __('Rafraichir')) {
				$cmd_name .= '_1';
			}
			$cmd = new virtualhistoryCmd();
			$cmd->setName($cmd_name);
			$cmd->setEqLogic_id($this->getId());
			$cmd->setIsVisible($cmd_def->getIsVisible());
			$cmd->setType($cmd_def->getType());
			$cmd->setUnite($cmd_def->getUnite());
			$cmd->setOrder($cmd_def->getOrder());
			$cmd->setDisplay('icon', $cmd_def->getDisplay('icon'));
			$cmd->setDisplay('invertBinary', $cmd_def->getDisplay('invertBinary'));
			foreach ($cmd_def->getTemplate() as $key => $value) {
				$cmd->setTemplate($key, $value);
			}
			$cmd->setSubType($cmd_def->getSubType());
			if ($cmd->getType() == 'info') {
				$cmd->setConfiguration('calcul', '#' . $cmd_def->getId() . '#');
				$cmd->setValue($cmd_def->getId());
			} else {
				$cmd->setValue($cmd_def->getValue());
				$cmd->setConfiguration('infoName', '#' . $cmd_def->getId() . '#');
			}
			try {
				$cmd->save();
			} catch (Exception $e) {

			}
		}
		$this->save();
	}

	/*     * **********************Getteur Setteur*************************** */
}

class virtualhistoryCmd extends cmd {
	/*     * *************************Attributs****************************** */

	/*     * ***********************Methode static*************************** */

	/*     * *********************Methode d'instance************************* */

	public function dontRemoveCmd() {
		if ($this->getLogicalId() == 'refresh') {
			return true;
		}
		return false;
	}

	public function preSave() {
		if ($this->getLogicalId() == 'refresh') {
			return;
		}
		if ($this->getConfiguration('virtualhistoryAction') == 1) {
			$actionInfo = virtualhistoryCmd::byEqLogicIdCmdName($this->getEqLogic_id(), $this->getName());
			if (is_object($actionInfo)) {
				$this->setId($actionInfo->getId());
			}
		}
		if ($this->getType() == 'action') {
			if ($this->getConfiguration('infoName') == '') {
				throw new Exception(__('Le nom de la commande info ne peut etre vide', __FILE__));
			}
			$cmd = cmd::byId(str_replace('#', '', $this->getConfiguration('infoName')));
			if (is_object($cmd)) {
				$this->setSubType($cmd->getSubType());
			} else {
				$actionInfo = virtualhistoryCmd::byEqLogicIdCmdName($this->getEqLogic_id(), $this->getConfiguration('infoName'));
				if (!is_object($actionInfo)) {
					$actionInfo = new virtualhistoryCmd();
					$actionInfo->setType('info');
					switch ($this->getSubType()) {
						case 'slider':
							$actionInfo->setSubType('numeric');
							break;
						default:
							$actionInfo->setSubType('string');
							break;
					}
				}
				$actionInfo->setConfiguration('virtualhistoryAction', 1);
				$actionInfo->setName($this->getConfiguration('infoName'));
				$actionInfo->setEqLogic_id($this->getEqLogic_id());
				$actionInfo->save();
				$this->setConfiguration('infoId', $actionInfo->getId());
			}
		} else {
			$calcul = $this->getConfiguration('calcul');
			if (strpos($calcul, '#' . $this->getId() . '#') !== false) {
				throw new Exception(__('Vous ne pouvez faire un calcul sur la valeur elle meme (boucle infinie)!!!', __FILE__));
			}
			preg_match_all("/#([0-9]*)#/", $calcul, $matches);
			$value = '';
			foreach ($matches[1] as $cmd_id) {
				if (is_numeric($cmd_id)) {
					$cmd = self::byId($cmd_id);
					if (is_object($cmd) && $cmd->getType() == 'info') {
						$value .= '#' . $cmd_id . '#';
					}
				}
			}
			preg_match_all("/variable\((.*?)\)/", $calcul, $matches);
			foreach ($matches[1] as $variable) {
				$value .= '#variable(' . $variable . ')#';
			}
			if ($value != '') {
				$this->setValue($value);
			}
		}
	}

	public function postSave() {
		if ($this->getType() == 'info' && $this->getConfiguration('virtualhistoryAction', 0) == '0' && $this->getConfiguration('calcul') != '') {
			$this->event($this->execute());
		}
	}

	public function execute($_options = null) {
		if ($this->getLogicalId() == 'refresh') {
			$this->getEqLogic()->refresh();
			return;
		}
		switch ($this->getType()) {
			case 'info':
				if ($this->getConfiguration('virtualhistoryAction', 0) == '0') {
					try {
						$result = jeedom::evaluateExpression($this->getConfiguration('calcul'));
						if ($this->getSubType() == 'numeric') {
							if (is_numeric($result)) {
								$result = number_format($result, 2);
							} else {
								$result = str_replace('"', '', $result);
							}
							if (strpos($result, '.') !== false) {
								$result = str_replace(',', '', $result);
							} else {
								$result = str_replace(',', '.', $result);
							}
						}
						return $result;
					} catch (Exception $e) {
						log::add('virtualhistory', 'info', $e->getMessage());
						return jeedom::evaluateExpression($this->getConfiguration('calcul'));
					}
				}
				break;
			case 'action':
				$virtualhistoryCmd = virtualhistoryCmd::byId($this->getConfiguration('infoId'));
				if (!is_object($virtualhistoryCmd)) {
					$cmds = explode('&&', $this->getConfiguration('infoName'));
					if (is_array($cmds)) {
						foreach ($cmds as $cmd_id) {
							$cmd = cmd::byId(str_replace('#', '', $cmd_id));
							if (is_object($cmd)) {
								$cmd->execCmd($_options);
							}
						}
						return;
					} else {
						$cmd = cmd::byId(str_replace('#', '', $this->getConfiguration('infoName')));
						return $cmd->execCmd($_options);
					}
				} else {
					if ($virtualhistoryCmd->getEqType() != 'virtualhistory') {
						throw new Exception(__('La cible de la commande virtuel n\'est pas un équipement de type virtuel', __FILE__));
					}
					if ($this->getSubType() == 'slider') {
						$value = $_options['slider'];
					} else if ($this->getSubType() == 'color') {
						$value = $_options['color'];
					} else {
						$value = $this->getConfiguration('value');
					}
					$result = jeedom::evaluateExpression($value);
					if ($this->getSubtype() == 'message') {
						$result = $_options['title'] . ' ' . $_options['message'];
					}
					$virtualhistoryCmd->event($result);
				}
				break;
		}
	}

	/*     * **********************Getteur Setteur*************************** */
}

?>
