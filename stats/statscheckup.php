<?php
/**
 * 2007-2016 PrestaShop
 *
 * Thirty Bees is an extension to the PrestaShop e-commerce software developed by PrestaShop SA
 * Copyright (C) 2017 Thirty Bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    Thirty Bees <modules@thirtybees.com>
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2017 Thirty Bees
 * @copyright 2007-2016 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 * PrestaShop is an internationally registered trademark & property of PrestaShop SA
*/

if (!defined('_TB_VERSION_')) {
    exit;
}

/**
 * Class StatsCheckUp
 *
 * @since 1.0.0
 */
class StatsCheckUp extends StatsModule
{
    private $html = '';

    /**
     * StatsCheckUp constructor.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->name = 'statscheckup';
        $this->tab = 'analytics_stats';
        $this->version = '2.0.0';
        $this->author = 'thirty bees';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Catalog evaluation');
        $this->description = $this->l('Adds a quick evaluation of your catalog quality to the Stats dashboard.');
        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];
    }

    /**
     * Install this module
     *
     * @return bool Indicates whether this module has been installed correctly
     *
     * @since 1.0.0
     */
    public function install()
    {
        $confs = [
            'CHECKUP_DESCRIPTIONS_LT' => 100,
            'CHECKUP_DESCRIPTIONS_GT' => 400,
            'CHECKUP_IMAGES_LT'       => 1,
            'CHECKUP_IMAGES_GT'       => 2,
            'CHECKUP_SALES_LT'        => 1,
            'CHECKUP_SALES_GT'        => 2,
            'CHECKUP_STOCK_LT'        => 1,
            'CHECKUP_STOCK_GT'        => 3,
        ];
        foreach ($confs as $confname => $confdefault) {
            if (!Configuration::get($confname)) {
                Configuration::updateValue($confname, (int) $confdefault);
            }
        }

        return (parent::install() && $this->registerHook('AdminStatsModules'));
    }

    /**
     * @return string
     *
     * @since 1.0.0
     */
    public function hookAdminStatsModules()
    {
        if (Tools::isSubmit('submitCheckup')) {
            $confs = [
                'CHECKUP_DESCRIPTIONS_LT',
                'CHECKUP_DESCRIPTIONS_GT',
                'CHECKUP_IMAGES_LT',
                'CHECKUP_IMAGES_GT',
                'CHECKUP_SALES_LT',
                'CHECKUP_SALES_GT',
                'CHECKUP_STOCK_LT',
                'CHECKUP_STOCK_GT',
            ];
            foreach ($confs as $confname) {
                Configuration::updateValue($confname, (int) Tools::getValue($confname));
            }
            echo '<div class="conf confirm"> '.$this->l('Configuration updated').'</div>';
        }

        if (Tools::isSubmit('submitCheckupOrder')) {
            $this->context->cookie->checkup_order = (int) Tools::getValue('submitCheckupOrder');
            echo '<div class="conf confirm"> '.$this->l('Configuration updated').'</div>';
        }

        if (!isset($this->context->cookie->checkup_order)) {
            $this->context->cookie->checkup_order = 1;
        }

        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $employee = Context::getContext()->employee;
        $prop30 = ((strtotime($employee->stats_date_to.' 23:59:59') - strtotime($employee->stats_date_from.' 00:00:00')) / 60 / 60 / 24) / 30;

        // Get languages
        $sql = 'SELECT l.*
				FROM '._DB_PREFIX_.'lang l'
            .Shop::addSqlAssociation('lang', 'l');
        $languages = $db->executeS($sql);

        $arrayColors = [
            0 => '<img src="../modules/'.$this->name.'/img/red.png" alt="'.$this->l('Bad').'" />',
            1 => '<img src="../modules/'.$this->name.'/img/orange.png" alt="'.$this->l('Average').'" />',
            2 => '<img src="../modules/'.$this->name.'/img/green.png" alt="'.$this->l('Good').'" />',
        ];
        $tokenProducts = Tools::getAdminToken('AdminProducts'.(int) Tab::getIdFromClassName('AdminProducts').(int) Context::getContext()->employee->id);
        $divisor = 4;
        $totals = ['products' => 0, 'active' => 0, 'images' => 0, 'sales' => 0, 'stock' => 0];
        foreach ($languages as $language) {
            $divisor++;
            $totals['description_'.$language['iso_code']] = 0;
        }

        $orderBy = 'p.id_product';
        if ($this->context->cookie->checkup_order == 2) {
            $orderBy = 'pl.name';
        } else {
            if ($this->context->cookie->checkup_order == 3) {
                $orderBy = 'nbSales DESC';
            }
        }

        // Get products stats
        $sql = 'SELECT p.id_product, product_shop.active, pl.name, (
					SELECT COUNT(*)
					FROM '._DB_PREFIX_.'image i
					'.Shop::addSqlAssociation('image', 'i').'
					WHERE i.id_product = p.id_product
				) as nbImages, (
					SELECT SUM(od.product_quantity)
					FROM '._DB_PREFIX_.'orders o
					LEFT JOIN '._DB_PREFIX_.'order_detail od ON o.id_order = od.id_order
					WHERE od.product_id = p.id_product
						AND o.invoice_date BETWEEN '.ModuleGraph::getDateBetween().'
						'.Shop::addSqlRestriction(Shop::SHARE_ORDER, 'o').'
				) as nbSales,
				IFNULL(stock.quantity, 0) as stock
				FROM '._DB_PREFIX_.'product p
				'.Shop::addSqlAssociation('product', 'p').'
				'.Product::sqlStock('p', 0).'
				LEFT JOIN '._DB_PREFIX_.'product_lang pl
					ON (p.id_product = pl.id_product AND pl.id_lang = '.(int) $this->context->language->id.Shop::addSqlRestrictionOnLang('pl').')
				ORDER BY '.$orderBy;
        $result = $db->executeS($sql);

        if (!$result) {
            return $this->l('No product was found.');
        }

        $arrayConf = [
            'DESCRIPTIONS' => ['name' => $this->l('Descriptions'), 'text' => $this->l('chars (without HTML)')],
            'IMAGES'       => ['name' => $this->l('Images'), 'text' => $this->l('images')],
            'SALES'        => ['name' => $this->l('Sales'), 'text' => $this->l('orders / month')],
            'STOCK'        => ['name' => $this->l('Available quantity for sale'), 'text' => $this->l('items')],
        ];

        $this->html = '
		<div class="panel-heading">'
            .$this->displayName.'
		</div>
		<form action="'.Tools::safeOutput(AdminController::$currentIndex.'&token='.Tools::getValue('token').'&module='.$this->name).'" method="post" class="checkup form-horizontal">
			<table class="table checkup">
				<thead>
					<tr>
						<th></th>
						<th><span class="title_box active">'.$arrayColors[0].' '.$this->l('Not enough').'</span></th>
						<th><span class="title_box active">'.$arrayColors[2].' '.$this->l('Alright').'</span></th>
					</tr>
				</thead>';
        foreach ($arrayConf as $conf => $translations) {
            $this->html .= '
				<tbody>
					<tr>
						<td>
							<label class="control-label col-lg-12">'.$translations['name'].'</label>
						</td>
						<td>
							<div class="row">
								<div class="col-lg-11 input-group">
									<span class="input-group-addon">'.$this->l('Less than').'</span>
									<input type="text" name="CHECKUP_'.$conf.'_LT" value="'.Tools::safeOutput(Tools::getValue('CHECKUP_'.$conf.'_LT', Configuration::get('CHECKUP_'.$conf.'_LT'))).'" />
									<span class="input-group-addon">'.$translations['text'].'</span>
								 </div>
							 </div>
						</td>
						<td>
							<div class="row">
								<div class="col-lg-12 input-group">
									<span class="input-group-addon">'.$this->l('Greater than').'</span>
									<input type="text" name="CHECKUP_'.$conf.'_GT" value="'.Tools::safeOutput(Tools::getValue('CHECKUP_'.$conf.'_GT', Configuration::get('CHECKUP_'.$conf.'_GT'))).'" />
									<span class="input-group-addon">'.$translations['text'].'</span>
								 </div>
							 </div>
						</td>
					</tr>
				</tbody>';
        }
        $this->html .= '</table>
			<button type="submit" name="submitCheckup" class="btn btn-default pull-right">
				<i class="icon-save"></i> '.$this->l('Save').'
			</button> 
		</form>
		<form action="'.Tools::safeOutput(AdminController::$currentIndex.'&token='.Tools::getValue('token').'&module='.$this->name).'" method="post" class="form-horizontal alert">
			<div class="row">
				<div class="col-lg-12">
					<label class="control-label pull-left">'.$this->l('Order by').'</label>
					<div class="col-lg-3">
						<select name="submitCheckupOrder" onchange="this.form.submit();">
							<option value="1">'.$this->l('ID').'</option>
							<option value="2" '.($this->context->cookie->checkup_order == 2 ? 'selected="selected"' : '').'>'.$this->l('Name').'</option>
							<option value="3" '.($this->context->cookie->checkup_order == 3 ? 'selected="selected"' : '').'>'.$this->l('Sales').'</option>
						</select>
					</div>
				</div>
			</div>
		</form>
		<div style="overflow-x:auto">
		<table class="table checkup2">
			<thead>
				<tr>
					<th><span class="title_box active">'.$this->l('ID').'</span></th>
					<th><span class="title_box active">'.$this->l('Item').'</span></th>
					<th class="center"><span class="title_box active">'.$this->l('Active').'</span></th>';
        foreach ($languages as $language) {
            $this->html .= '<th><span class="title_box active">'.$this->l('Desc.').' ('.Tools::strtoupper($language['iso_code']).')</span></th>';
        }
        $this->html .= '
					<th class="center"><span class="title_box active">'.$this->l('Images').'</span></th>
					<th class="center"><span class="title_box active">'.$this->l('Sales').'</span></th>
					<th class="center"><span class="title_box active">'.$this->l('Available quantity for sale').'</span></th>
					<th class="center"><span class="title_box active">'.$this->l('Global').'</span></th>
				</tr>
			</thead>
			<tbody>';
        foreach ($result as $row) {
            $totals['products']++;
            $scores = [
                'active' => ($row['active'] ? 2 : 0),
                'images' => ($row['nbImages'] < Configuration::get('CHECKUP_IMAGES_LT') ? 0 : ($row['nbImages'] > Configuration::get('CHECKUP_IMAGES_GT') ? 2 : 1)),
                'sales'  => (($row['nbSales'] * $prop30 < Configuration::get('CHECKUP_SALES_LT')) ? 0 : (($row['nbSales'] * $prop30 > Configuration::get('CHECKUP_SALES_GT')) ? 2 : 1)),
                'stock'  => (($row['stock'] < Configuration::get('CHECKUP_STOCK_LT')) ? 0 : (($row['stock'] > Configuration::get('CHECKUP_STOCK_GT')) ? 2 : 1)),
            ];
            $totals['active'] += (int) $scores['active'];
            $totals['images'] += (int) $scores['images'];
            $totals['sales'] += (int) $scores['sales'];
            $totals['stock'] += (int) $scores['stock'];
            $descriptions = $db->executeS(
                '
				SELECT l.iso_code, pl.description
				FROM '._DB_PREFIX_.'product_lang pl
				LEFT JOIN '._DB_PREFIX_.'lang l
					ON pl.id_lang = l.id_lang
				WHERE id_product = '.(int) $row['id_product'].Shop::addSqlRestrictionOnLang('pl')
            );
            foreach ($descriptions as $description) {
                if (isset($description['iso_code']) && isset($description['description'])) {
                    $row['desclength_'.$description['iso_code']] = Tools::strlen(strip_tags($description['description']));
                }
                if (isset($description['iso_code'])) {
                    $scores['description_'.$description['iso_code']] = ($row['desclength_'.$description['iso_code']] < Configuration::get('CHECKUP_DESCRIPTIONS_LT') ? 0 : ($row['desclength_'.$description['iso_code']] > Configuration::get('CHECKUP_DESCRIPTIONS_GT') ? 2 : 1));
                    $totals['description_'.$description['iso_code']] += $scores['description_'.$description['iso_code']];
                }
            }
            $scores['average'] = array_sum($scores) / $divisor;
            $scores['average'] = ($scores['average'] < 1 ? 0 : ($scores['average'] > 1.5 ? 2 : 1));

            $this->html .= '
				<tr>
					<td>'.$row['id_product'].'</td>
					<td><a href="'.Tools::safeOutput('index.php?tab=AdminProducts&updateproduct&id_product='.$row['id_product'].'&token='.$tokenProducts).'">'.Tools::substr($row['name'], 0, 42).'</a></td>
					<td class="center">'.$arrayColors[$scores['active']].'</td>';
            foreach ($languages as $language) {
                if (isset($row['desclength_'.$language['iso_code']])) {
                    $this->html .= '<td class="center">'.(int) $row['desclength_'.$language['iso_code']].' '.$arrayColors[$scores['description_'.$language['iso_code']]].'</td>';
                } else {
                    $this->html .= '<td>0 '.$arrayColors[0].'</td>';
                }
            }
            $this->html .= '
					<td class="center">'.(int) $row['nbImages'].' '.$arrayColors[$scores['images']].'</td>
					<td class="center">'.(int) $row['nbSales'].' '.$arrayColors[$scores['sales']].'</td>
					<td class="center">'.(int) $row['stock'].' '.$arrayColors[$scores['stock']].'</td>
					<td class="center">'.$arrayColors[$scores['average']].'</td>
				</tr>';
        }

        $this->html .= '</tbody>';

        $totals['active'] = $totals['active'] / $totals['products'];
        $totals['active'] = ($totals['active'] < 1 ? 0 : ($totals['active'] > 1.5 ? 2 : 1));
        $totals['images'] = $totals['images'] / $totals['products'];
        $totals['images'] = ($totals['images'] < 1 ? 0 : ($totals['images'] > 1.5 ? 2 : 1));
        $totals['sales'] = $totals['sales'] / $totals['products'];
        $totals['sales'] = ($totals['sales'] < 1 ? 0 : ($totals['sales'] > 1.5 ? 2 : 1));
        $totals['stock'] = $totals['stock'] / $totals['products'];
        $totals['stock'] = ($totals['stock'] < 1 ? 0 : ($totals['stock'] > 1.5 ? 2 : 1));
        foreach ($languages as $language) {
            $totals['description_'.$language['iso_code']] = $totals['description_'.$language['iso_code']] / $totals['products'];
            $totals['description_'.$language['iso_code']] = ($totals['description_'.$language['iso_code']] < 1 ? 0 : ($totals['description_'.$language['iso_code']] > 1.5 ? 2 : 1));
        }
        $totals['average'] = array_sum($totals) / $divisor;
        $totals['average'] = ($totals['average'] < 1 ? 0 : ($totals['average'] > 1.5 ? 2 : 1));

        $this->html .= '
			<tfoot>
				<tr>
					<th colspan="2"></th>
					<th class="center"><span class="title_box active">'.$this->l('Active').'</span></th>';
        foreach ($languages as $language) {
            $this->html .= '<th class="center"><span class="title_box active">'.$this->l('Desc.').' ('.Tools::strtoupper($language['iso_code']).')</span></th>';
        }
        $this->html .= '
					<th class="center"><span class="title_box active">'.$this->l('Images').'</span></th>
					<th class="center"><span class="title_box active">'.$this->l('Sales').'</span></th>
					<th class="center"><span class="title_box active">'.$this->l('Available quantity for sale').'</span></th>
					<th class="center"><span class="title_box active">'.$this->l('Global').'</span></th>
				</tr>
				<tr>
					<td colspan="2"></td>
					<td class="center">'.$arrayColors[$totals['active']].'</td>';
        foreach ($languages as $language) {
            $this->html .= '<td class="center">'.$arrayColors[$totals['description_'.$language['iso_code']]].'</td>';
        }
        $this->html .= '
					<td class="center">'.$arrayColors[$totals['images']].'</td>
					<td class="center">'.$arrayColors[$totals['sales']].'</td>
					<td class="center">'.$arrayColors[$totals['stock']].'</td>
					<td class="center">'.$arrayColors[$totals['average']].'</td>
				</tr>
			</tfoot>
		</table></div>';

        return $this->html;
    }
}
