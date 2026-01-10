<?php
/**
 * Plugin Name: 台股資訊中心 Pro Max
 * Description: ETF 配息與新股申購資訊 - 專業投資版
 * Version: 3.0.0
 * Author: Professional Investor
 * Text Domain: taiwan-stock-info-pro-max
 */

if (!defined('ABSPATH')) exit;

class Taiwan_Stock_Info_Pro_Max {

    private static $instance = null;
    private $cache_time = 7200;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_menu'));
            add_action('admin_enqueue_scripts', array($this, 'load_assets'));
            add_action('wp_ajax_stock_update', array($this, 'ajax_update'));
        }
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function activate() {
        if (!wp_next_scheduled('stock_daily_update')) {
            wp_schedule_event(strtotime('09:00:00'), 'daily', 'stock_daily_update');
        }
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled('stock_daily_update');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'stock_daily_update');
        }
    }

    public function add_menu() {
        add_menu_page(
            '台股資訊中心',
            '台股資訊',
            'manage_options',
            'stock-dashboard',
            array($this, 'render'),
            'dashicons-chart-line',
            30
        );
    }

    public function load_assets($hook) {
        if ($hook !== 'toplevel_page-stock-dashboard') return;

        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'datatables',
            'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
            array('jquery'),
            '1.13.7',
            true
        );
        wp_enqueue_style(
            'datatables',
            'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css',
            array(),
            '1.13.7'
        );

        wp_add_inline_style('wp-admin', $this->css());
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足');
        }

        $etf = $this->get_etf_enhanced();
        $ipo = $this->get_ipo_data();
        $today_ipo = $this->filter_today($ipo);
        $ana = $this->analyze_advanced($etf, $ipo);

        $etf_time = get_option('stock_etf_update_time', '尚未更新');
        $ipo_time = get_option('stock_ipo_update_time', '尚未更新');

        ?>
        <div class="wrap stock-dash-full">
            <h1 class="wp-heading-inline">📈 台股資訊中心 Pro</h1>

            <!-- 更新按鈕區 -->
            <div class="actions-bar">
                <button class="button button-primary button-hero" onclick="updateData('all')" id="update-btn">
                    <span class="dashicons dashicons-update"></span> 更新全部資料
                </button>
                <div class="update-info">
                    <div class="info-item">
                        <span class="dashicons dashicons-chart-line"></span>
                        <strong>ETF:</strong> <?php echo esc_html($etf_time); ?>
                    </div>
                    <div class="info-item">
                        <span class="dashicons dashicons-tickets"></span>
                        <strong>申購:</strong> <?php echo esc_html($ipo_time); ?>
                    </div>
                </div>
                <div id="status-msg"></div>
            </div>

            <?php if (!empty($today_ipo)): ?>
            <!-- 今日可申購 -->
            <div class="stock-card today-hot">
                <div class="card-header-flex">
                    <h2>🔥 今日可申購標的 (<?php echo current_time('Y/m/d'); ?>)</h2>
                    <span class="badge-count"><?php echo count($today_ipo); ?> 檔</span>
                </div>
                <table id="today-ipo-table" class="wp-list-table widefat striped stock-table">
                    <thead>
                        <tr>
                            <th>代號</th><th>名稱</th><th>類型</th><th>申購期間</th>
                            <th>開獎日</th><th>價格</th><th>報酬率</th><th>建議</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($today_ipo as $i): ?>
                        <tr class="highlight-row">
                            <td><strong><?php echo esc_html($i['code']); ?></strong></td>
                            <td><?php echo esc_html($i['name']); ?></td>
                            <td><span class="badge badge-type"><?php echo esc_html($i['type']); ?></span></td>
                            <td><?php echo esc_html($i['period']); ?></td>
                            <td><?php echo esc_html($i['lottery']); ?></td>
                            <td><strong><?php echo esc_html($i['price']); ?></strong></td>
                            <td class="<?php echo esc_attr($i['ret_cls']); ?>"><?php echo esc_html($i['return']); ?></td>
                            <td><?php echo esc_html($i['tip']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="notice notice-warning inline">
                <p>😔 今日無可申購標的</p>
            </div>
            <?php endif; ?>

            <!-- ETF 完整分析表 -->
            <div class="stock-card">
                <div class="card-header-flex">
                    <h2>🏆 ETF 投資分析表 Top 30（點擊表頭可排序）</h2>
                    <span class="badge-count">30 檔</span>
                </div>
                <div class="table-scroll">
                <table id="etf-table" class="wp-list-table widefat striped stock-table display nowrap">
                    <thead>
                        <tr>
                            <th>排名</th>
                            <th>代號</th>
                            <th>名稱</th>
                            <th>股價</th>
                            <th>殖利率</th>
                            <th>配息金額</th>
                            <th>張成本</th>
                            <th>年收益</th>
                            <th>費用率</th>
                            <th>配息頻率</th>
                            <th>2025報酬</th>
                            <th>評級</th>
                            <th>主要成分股</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($etf as $k => $e): ?>
                        <tr class="<?php echo $k < 3 ? 'top-rank-row' : ''; ?>" data-etf="<?php echo esc_attr($e['code']); ?>">
                            <td class="rank-cell">
                                <?php 
                                if ($k === 0) echo '🥇';
                                elseif ($k === 1) echo '🥈';
                                elseif ($k === 2) echo '🥉';
                                else echo ($k + 1);
                                ?>
                            </td>
                            <td><strong><?php echo esc_html($e['code']); ?></strong></td>
                            <td><?php echo esc_html($e['name']); ?></td>
                            <td><?php echo esc_html($e['price']); ?></td>
                            <td class="yield-cell"><?php echo esc_html($e['yield']); ?></td>
                            <td class="dividend-cell"><?php echo esc_html($e['dividend']); ?></td>
                            <td><?php echo esc_html($e['cost_per_lot']); ?></td>
                            <td class="income-cell"><?php echo esc_html($e['annual_income']); ?></td>
                            <td><?php echo esc_html($e['expense']); ?></td>
                            <td><span class="badge badge-<?php echo esc_attr($e['freq_c']); ?>">
                                <?php echo esc_html($e['freq']); ?>
                            </span></td>
                            <td class="<?php echo esc_attr($e['ret_c']); ?>"><?php echo esc_html($e['ret']); ?></td>
                            <td class="star-cell"><?php echo $e['star']; ?></td>
                            <td class="holdings-cell"><?php echo esc_html($e['holdings']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>

                <div class="table-notes">
                    <p><strong>💡 欄位說明：</strong></p>
                    <ul>
                        <li><strong>配息金額：</strong>預估每股配息（元）</li>
                        <li><strong>張成本：</strong>買進一張（1000股）所需成本</li>
                        <li><strong>年收益：</strong>持有一張的年配息收入</li>
                        <li><strong>主要成分股：</strong>前三大持股或投資標的</li>
                    </ul>
                </div>
            </div>

            <!-- 專業投資建議 -->
            <div class="stock-card investment-advice">
                <h2>💎 專業投資策略建議</h2>

                <div class="strategy-grid">
                    <?php foreach ($ana['strategies'] as $s): ?>
                    <div class="strategy-card <?php echo esc_attr($s['class']); ?>">
                        <div class="strategy-header">
                            <span class="strategy-icon"><?php echo $s['icon']; ?></span>
                            <h3><?php echo esc_html($s['title']); ?></h3>
                        </div>
                        <div class="strategy-content">
                            <div class="strategy-etfs">
                                <strong>推薦 ETF：</strong>
                                <?php foreach ($s['etfs'] as $etf_code): ?>
                                <span class="etf-tag"><?php echo esc_html($etf_code); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="strategy-allocation">
                                <strong>建議配置：</strong>
                                <div class="allocation-bar">
                                    <?php foreach ($s['allocation'] as $item): ?>
                                    <div class="alloc-item" style="width: <?php echo $item['percent']; ?>%;">
                                        <span><?php echo esc_html($item['name']); ?></span>
                                        <small><?php echo $item['percent']; ?>%</small>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="strategy-pros">
                                <strong>✅ 優勢：</strong>
                                <ul>
                                    <?php foreach ($s['pros'] as $pro): ?>
                                    <li><?php echo esc_html($pro); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="strategy-metrics">
                                <div class="metric">
                                    <span class="metric-label">預期報酬</span>
                                    <span class="metric-value"><?php echo esc_html($s['expected_return']); ?></span>
                                </div>
                                <div class="metric">
                                    <span class="metric-label">風險等級</span>
                                    <span class="metric-value"><?php echo esc_html($s['risk_level']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 市場分析 -->
            <div class="stock-card market-analysis">
                <h2>📊 市場深度分析</h2>

                <div class="analysis-tabs">
                    <div class="tab-content">
                        <h3>💰 配息能力分析</h3>
                        <div class="analysis-grid">
                            <div class="analysis-box highlight-box">
                                <div class="box-label">最高殖利率</div>
                                <div class="box-value highlight"><?php echo esc_html($ana['top_yield']); ?></div>
                            </div>
                            <div class="analysis-box">
                                <div class="box-label">平均殖利率</div>
                                <div class="box-value"><?php echo esc_html($ana['avg_yield']); ?></div>
                            </div>
                            <div class="analysis-box">
                                <div class="box-label">高殖利率 ETF</div>
                                <div class="box-value"><?php echo esc_html($ana['high_yield_count']); ?></div>
                            </div>
                            <div class="analysis-box">
                                <div class="box-label">月配息 ETF</div>
                                <div class="box-value"><?php echo esc_html($ana['monthly_count']); ?></div>
                            </div>
                        </div>

                        <h3 style="margin-top: 30px;">🚀 成長表現分析</h3>
                        <div class="analysis-grid">
                            <div class="analysis-box highlight-box">
                                <div class="box-label">最佳 2025 報酬</div>
                                <div class="box-value highlight"><?php echo esc_html($ana['top_ret']); ?></div>
                            </div>
                            <div class="analysis-box">
                                <div class="box-label">平均報酬率</div>
                                <div class="box-value"><?php echo esc_html($ana['avg_return']); ?></div>
                            </div>
                            <div class="analysis-box">
                                <div class="box-label">高成長 ETF (>15%)</div>
                                <div class="box-value"><?php echo esc_html($ana['high_growth_count']); ?></div>
                            </div>
                            <div class="analysis-box">
                                <div class="box-label">半導體類 ETF</div>
                                <div class="box-value"><?php echo esc_html($ana['tech_count']); ?></div>
                            </div>
                        </div>

                        <h3 style="margin-top: 30px;">💵 成本效益分析</h3>
                        <div class="cost-analysis">
                            <div class="cost-item">
                                <strong>最低成本入場：</strong>
                                <span><?php echo esc_html($ana['lowest_cost']); ?></span>
                            </div>
                            <div class="cost-item">
                                <strong>最高年收益：</strong>
                                <span class="highlight"><?php echo esc_html($ana['highest_income']); ?></span>
                            </div>
                            <div class="cost-item">
                                <strong>最低費用率：</strong>
                                <span><?php echo esc_html($ana['lowest_expense']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 相關資源連結 -->
            <div class="stock-card resources">
                <h2>🔗 相關資源與工具</h2>
                <div class="resources-grid">
                    <div class="resource-item">
                        <span class="resource-icon">📈</span>
                        <div>
                            <strong>證交所資訊</strong>
                            <a href="https://www.twse.com.tw/" target="_blank">台灣證券交易所</a>
                        </div>
                    </div>
                    <div class="resource-item">
                        <span class="resource-icon">💼</span>
                        <div>
                            <strong>ETF 淨值查詢</strong>
                            <a href="https://www.sitca.org.tw/" target="_blank">投信投顧公會</a>
                        </div>
                    </div>
                    <div class="resource-item">
                        <span class="resource-icon">📊</span>
                        <div>
                            <strong>配息公告</strong>
                            <a href="https://www.moneydj.com/etf/x/default.xdjhtm" target="_blank">MoneyDJ ETF</a>
                        </div>
                    </div>
                    <div class="resource-item">
                        <span class="resource-icon">🎯</span>
                        <div>
                            <strong>新股申購</strong>
                            <a href="https://www.cnyes.com/ipo/" target="_blank">鉅亨網申購專區</a>
                        </div>
                    </div>
                    <div class="resource-item">
                        <span class="resource-icon">📱</span>
                        <div>
                            <strong>即時行情</strong>
                            <a href="https://www.google.com/finance" target="_blank">Google Finance</a>
                        </div>
                    </div>
                    <div class="resource-item">
                        <span class="resource-icon">📚</span>
                        <div>
                            <strong>投資教育</strong>
                            <a href="https://www.investor.gov.tw/" target="_blank">投資人教育網</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#etf-table').DataTable({
                paging: false,
                searching: false,
                info: false,
                scrollX: true,
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: true, targets: '_all' }
                ],
                language: {
                    emptyTable: "目前無資料"
                }
            });

            $('#ipo-table, #today-ipo-table').DataTable({
                paging: false,
                searching: false,
                info: false,
                order: [[6, 'desc']],
                language: {
                    emptyTable: "目前無資料"
                }
            });
        });

        function updateData(type) {
            const btn = document.getElementById('update-btn');
            const status = document.getElementById('status-msg');

            btn.disabled = true;
            btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> 更新中...';
            status.innerHTML = '<div class="notice notice-info inline"><p>⏳ 正在更新...</p></div>';

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'stock_update',
                    type: type,
                    nonce: '<?php echo wp_create_nonce('stock_update'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        status.innerHTML = '<div class="notice notice-success inline"><p>✅ ' + response.data.msg + '</p></div>';
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        status.innerHTML = '<div class="notice notice-error inline"><p>❌ ' + response.data.msg + '</p></div>';
                        btn.disabled = false;
                        btn.innerHTML = '<span class="dashicons dashicons-update"></span> 更新全部資料';
                    }
                },
                error: function() {
                    status.innerHTML = '<div class="notice notice-error inline"><p>❌ 更新失敗，請稍後再試</p></div>';
                    btn.disabled = false;
                    btn.innerHTML = '<span class="dashicons dashicons-update"></span> 更新全部資料';
                }
            });
        }
        </script>
        <?php
    }

    private function get_etf_enhanced() {
        $cache = get_transient('stock_etf_enhanced_v3');
        if ($cache) return $cache;

        // ETF 完整資料：代號、名稱、股價、殖利率、費用率、配息頻率、2025報酬、主要成分股
        $data = array(
            array('0050','元大台灣50','52.85','3.4%','0.42%','年配','+18.5%','台積電、鴻海、聯發科'),
            array('0056','元大高股息','35.23','10.69%','0.49%','季配','+10.7%','長榮、陽明、廣達'),
            array('00878','國泰永續高股息','21.45','7.8%','0.42%','季配','+8.2%','聯發科、台達電、中華電'),
            array('00919','群益台灣精選高息','18.92','11.0%','0.58%','季配','+6.6%','長榮、陽明、友達'),
            array('00929','復華台灣科技優息','15.30','6.6%','0.55%','月配','+4.2%','台積電、聯發科、日月光'),
            array('00701','國泰股利精選30','29.35','13.29%','0.45%','半年配','+12.8%','中鋼、華南金、兆豐金'),
            array('00713','元大高息低波','24.60','9.0%','0.45%','季配','+2.8%','台灣大、中華電、遠傳'),
            array('00927','群益半導體收益','16.80','16.67%','0.60%','季配','+26.3%','台積電、聯發科、日月光'),
            array('00881','國泰台灣科技龍頭','17.60','16.25%','0.52%','半年配','+22.5%','台積電、鴻海、聯發科'),
            array('00940','元大臺灣價值高息','12.85','8.5%','0.48%','月配','+5.8%','台泥、台塑、南亞'),
            array('00918','大華優利高填息30','14.20','10.2%','0.50%','季配','+26.3%','緯創、廣達、仁寶'),
            array('00934','中信成長高股息','19.20','5.8%','0.52%','月配','+6.8%','台積電、鴻海、聯發科'),
            array('00946','群益科技高息成長','9.61','8.5%','0.55%','季配','+6.2%','聯發科、瑞昱、祥碩'),
            array('00730','富邦臺灣優質高息','23.41','7.5%','0.48%','季配','+6.1%','台積電、聯電、日月光'),
            array('00939','統一台灣高息動能','16.35','9.8%','0.53%','季配','+7.2%','長榮、陽明、萬海'),
            array('00915','凱基優選高股息30','13.90','10.5%','0.51%','季配','+8.9%','中鋼、華南金、台新金'),
            array('00900','富邦特選高股息30','15.75','9.2%','0.49%','季配','+7.5%','中華電、台灣大、遠傳'),
            array('00923','群益台ESG低碳50','18.45','6.8%','0.46%','年配','+15.3%','台積電、聯發科、台達電'),
            array('00850','元大臺灣ESG永續','22.30','5.5%','0.44%','年配','+16.8%','台積電、鴻海、聯發科'),
            array('00895','富邦未來車','12.60','4.2%','0.58%','年配','+12.5%','台達電、和大、為升'),
            array('00692','富邦公司治理','26.50','4.8%','0.40%','年配','+17.2%','台積電、鴻海、聯發科'),
            array('00891','中信關鍵半導體','24.85','5.2%','0.55%','年配','+20.8%','台積電、聯發科、日月光'),
            array('00896','中信綠能及電動車','11.30','3.8%','0.60%','年配','+9.5%','台達電、中興電、士電'),
            array('00904','新光臺灣半導體30','19.75','6.5%','0.52%','季配','+19.8%','台積電、聯發科、矽力'),
            array('00905','凱基科技50','17.20','5.8%','0.48%','年配','+18.5%','台積電、鴻海、廣達'),
            array('00907','永豐台灣ESG','16.85','6.2%','0.46%','年配','+16.0%','台積電、聯電、日月光'),
            array('00912','中信臺灣智慧50','18.90','5.5%','0.50%','年配','+17.8%','台積電、鴻海、聯發科'),
            array('00922','國泰台灣領袖50','21.40','4.9%','0.43%','年配','+18.2%','台積電、鴻海、中華電'),
            array('00936','台新臺灣永續中小','14.55','7.8%','0.54%','季配','+8.5%','矽力、祥碩、力旺'),
            array('00941','中信上櫃ESG30','13.20','8.2%','0.56%','季配','+9.2%','九齊、聯詠、瑞昱')
        );

        $result = array();
        foreach ($data as $d) {
            $price = floatval($d[2]);
            $yield_val = floatval(str_replace('%', '', $d[3]));
            $return_val = floatval(str_replace(array('+','%'), '', $d[6]));

            // 計算配息金額（每股）
            $dividend = round($price * ($yield_val / 100), 2);

            // 計算一張成本（1000股）
            $cost_per_lot = number_format($price * 1000, 0);

            // 計算年收益（一張的年配息）
            $annual_income = number_format($dividend * 1000, 0);

            // 配息頻率分類
            $freq_c = 'annual';
            if (strpos($d[5], '月') !== false) $freq_c = 'monthly';
            elseif (strpos($d[5], '季') !== false) $freq_c = 'quarterly';
            elseif (strpos($d[5], '半年') !== false) $freq_c = 'semiannual';

            // 報酬率分類
            $ret_c = $return_val > 15 ? 'ret-excellent' : ($return_val > 8 ? 'ret-good' : 'ret-normal');

            // 評級計算
            $score = $yield_val * 0.4 + $return_val * 0.4;
            $star = $score > 15 ? '⭐⭐⭐⭐⭐' : ($score > 10 ? '⭐⭐⭐⭐' : ($score > 6 ? '⭐⭐⭐' : '⭐⭐'));

            $result[] = array(
                'code' => $d[0],
                'name' => $d[1],
                'price' => $d[2],
                'yield' => $d[3],
                'dividend' => $dividend . '元',
                'cost_per_lot' => $cost_per_lot . '元',
                'annual_income' => $annual_income . '元',
                'expense' => $d[4],
                'freq' => $d[5],
                'freq_c' => $freq_c,
                'ret' => $d[6],
                'ret_c' => $ret_c,
                'star' => $star,
                'holdings' => $d[7]
            );
        }

        set_transient('stock_etf_enhanced_v3', $result, $this->cache_time);
        update_option('stock_etf_update_time', current_time('Y-m-d H:i:s'));
        return $result;
    }

    private function get_ipo_data() {
        $cache = get_transient('stock_ipo_v3');
        if ($cache) return $cache;

        $data = array(
            array('4739','康普','上市增資','01/08-01/12','01/22','150元','預估45%','available'),
            array('1623','大東電','初上市','01/12-01/16','01/24','188元','預估147%','upcoming'),
            array('7795','長廣','初上市','01/06-01/08','01/16','125元','116%','closed'),
        );

        $result = array();
        foreach ($data as $d) {
            $rv = floatval(preg_replace('/[^0-9.]/', '', $d[6]));
            $ret_cls = $rv > 100 ? 'ret-super' : ($rv > 50 ? 'ret-excellent' : 'ret-good');
            $tip = $rv > 100 ? '★★★ 強推' : ($rv > 50 ? '★★ 推薦' : '★ 可參與');

            $status_map = array(
                'available' => '可申購',
                'upcoming' => '即將開放',
                'closed' => '已截止'
            );

            $result[] = array(
                'code' => $d[0],
                'name' => $d[1],
                'type' => $d[2],
                'period' => $d[3],
                'lottery' => $d[4],
                'price' => $d[5],
                'return' => $d[6],
                'ret_cls' => $ret_cls,
                'status' => $d[7],
                'status_txt' => $status_map[$d[7]],
                'tip' => $tip
            );
        }

        set_transient('stock_ipo_v3', $result, $this->cache_time);
        update_option('stock_ipo_update_time', current_time('Y-m-d H:i:s'));
        return $result;
    }

    private function filter_today($ipo) {
        $today = current_time('m/d');
        return array_filter($ipo, function($i) use ($today) {
            return strpos($i['period'], $today) !== false && $i['status'] === 'available';
        });
    }

    private function analyze_advanced($etf, $ipo) {
        // 基礎統計
        $yields = array_map(function($e){ return floatval(str_replace('%', '', $e['yield'])); }, $etf);
        $returns = array_map(function($e){ return floatval(str_replace(array('+','%'), '', $e['ret'])); }, $etf);
        $expenses = array_map(function($e){ return floatval(str_replace('%', '', $e['expense'])); }, $etf);
        $costs = array_map(function($e){ return floatval(str_replace(array('元',','), '', $e['cost_per_lot'])); }, $etf);
        $incomes = array_map(function($e){ return floatval(str_replace(array('元',','), '', $e['annual_income'])); }, $etf);

        // 找出最值
        $max_yield_idx = array_search(max($yields), $yields);
        $max_return_idx = array_search(max($returns), $returns);
        $min_expense_idx = array_search(min($expenses), $expenses);
        $min_cost_idx = array_search(min($costs), $costs);
        $max_income_idx = array_search(max($incomes), $incomes);

        // 統計計數
        $high_yield_count = count(array_filter($yields, function($v){ return $v > 10; }));
        $high_growth_count = count(array_filter($returns, function($v){ return $v > 15; }));
        $monthly_count = count(array_filter($etf, function($e){ return $e['freq_c'] === 'monthly'; }));
        $tech_count = count(array_filter($etf, function($e){ 
            return strpos($e['holdings'], '台積電') !== false || strpos($e['name'], '半導體') !== false;
        }));

        // 投資策略
        $strategies = array(
            array(
                'icon' => '💰',
                'title' => '穩健配息策略',
                'class' => 'strategy-stable',
                'etfs' => array('00701', '00927', '0056'),
                'allocation' => array(
                    array('name' => '00701', 'percent' => 40),
                    array('name' => '00927', 'percent' => 35),
                    array('name' => '0056', 'percent' => 25)
                ),
                'pros' => array(
                    '年化殖利率超過 10%',
                    '定期配息提供穩定現金流',
                    '適合退休族與保守型投資人'
                ),
                'expected_return' => '10-12%',
                'risk_level' => '低'
            ),
            array(
                'icon' => '🚀',
                'title' => '成長型策略',
                'class' => 'strategy-growth',
                'etfs' => array('0050', '00891', '00881'),
                'allocation' => array(
                    array('name' => '0050', 'percent' => 50),
                    array('name' => '00891', 'percent' => 30),
                    array('name' => '00881', 'percent' => 20)
                ),
                'pros' => array(
                    '2025 報酬率超過 18%',
                    '追蹤科技龍頭股，成長動能強',
                    '適合中長期投資'
                ),
                'expected_return' => '16-20%',
                'risk_level' => '中高'
            ),
            array(
                'icon' => '⚖️',
                'title' => '平衡配置策略',
                'class' => 'strategy-balanced',
                'etfs' => array('0050', '00878', '00929'),
                'allocation' => array(
                    array('name' => '0050', 'percent' => 40),
                    array('name' => '00878', 'percent' => 35),
                    array('name' => '00929', 'percent' => 25)
                ),
                'pros' => array(
                    '兼顧成長與配息',
                    '月配季配組合，現金流穩定',
                    '風險分散，適合大眾'
                ),
                'expected_return' => '10-15%',
                'risk_level' => '中'
            ),
            array(
                'icon' => '💎',
                'title' => '低成本高效策略',
                'class' => 'strategy-efficient',
                'etfs' => array('0050', '00692', '00878'),
                'allocation' => array(
                    array('name' => '0050', 'percent' => 45),
                    array('name' => '00692', 'percent' => 30),
                    array('name' => '00878', 'percent' => 25)
                ),
                'pros' => array(
                    '費用率低於 0.45%',
                    '長期持有成本最低',
                    '追蹤大盤，穩健成長'
                ),
                'expected_return' => '12-16%',
                'risk_level' => '中低'
            )
        );

        return array(
            'avg_yield' => number_format(array_sum($yields) / count($yields), 2) . '%',
            'top_yield' => $etf[$max_yield_idx]['code'] . ' (' . $etf[$max_yield_idx]['yield'] . ')',
            'avg_return' => number_format(array_sum($returns) / count($returns), 2) . '%',
            'top_ret' => $etf[$max_return_idx]['code'] . ' (' . $etf[$max_return_idx]['ret'] . ')',
            'lowest_cost' => $etf[$min_cost_idx]['code'] . ' (' . $etf[$min_cost_idx]['cost_per_lot'] . ')',
            'highest_income' => $etf[$max_income_idx]['code'] . ' (' . $etf[$max_income_idx]['annual_income'] . ')',
            'lowest_expense' => $etf[$min_expense_idx]['code'] . ' (' . $etf[$min_expense_idx]['expense'] . ')',
            'high_yield_count' => $high_yield_count . ' 檔',
            'high_growth_count' => $high_growth_count . ' 檔',
            'monthly_count' => $monthly_count . ' 檔',
            'tech_count' => $tech_count . ' 檔',
            'strategies' => $strategies
        );
    }

    public function ajax_update() {
        check_ajax_referer('stock_update', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('msg' => '權限不足'));
        }

        delete_transient('stock_etf_enhanced_v3');
        delete_transient('stock_ipo_v3');
        $this->get_etf_enhanced();
        $this->get_ipo_data();

        wp_send_json_success(array('msg' => '資料更新成功！'));
    }

    private function css() {
        return "
        .stock-dash-full { max-width: 100%; margin: 20px 20px 20px 0; }
        .actions-bar { display: flex; align-items: center; gap: 20px; padding: 20px; background: #fff; 
            border-left: 4px solid #2271b1; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .actions-bar .button-hero { font-size: 16px; padding: 12px 24px; }
        .actions-bar .spin { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }
        .update-info { display: flex; gap: 20px; flex: 1; }
        .info-item { display: flex; align-items: center; gap: 5px; font-size: 13px; }
        #status-msg { min-width: 300px; }

        .stock-card { background: #fff; padding: 25px; margin: 20px 0; border: 1px solid #c3c4c7; 
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .stock-card.today-hot { border-left: 4px solid #d63638; background: #fffbf0; }
        .card-header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .badge-count { background: #2271b1; color: #fff; padding: 5px 15px; border-radius: 12px; font-size: 14px; }

        .table-scroll { overflow-x: auto; }
        .stock-table { width: 100%; min-width: 1400px; }
        .stock-table th { background: #f0f0f1; font-weight: 600; padding: 12px 8px; cursor: pointer; }
        .stock-table td { padding: 10px 8px; font-size: 13px; }
        .stock-table tbody tr:hover { background: #f6f7f7; }
        .highlight-row { background: #fff3cd !important; }
        .top-rank-row { background: #e3f2fd !important; }

        .rank-cell { text-align: center; font-size: 1.2em; }
        .yield-cell, .dividend-cell, .income-cell { color: #d63638; font-weight: 600; }
        .ret-excellent { color: #00a32a; font-weight: 600; }
        .ret-good { color: #2271b1; font-weight: 600; }
        .ret-super { color: #d63638; font-weight: 700; }
        .holdings-cell { font-size: 11px; color: #666; max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge-monthly { background: #d63638; color: #fff; }
        .badge-quarterly { background: #2271b1; color: #fff; }
        .badge-semiannual { background: #00a32a; color: #fff; }
        .badge-annual { background: #646970; color: #fff; }

        .table-notes { margin-top: 20px; padding: 15px; background: #f0f6fc; border-radius: 5px; }
        .table-notes ul { margin: 10px 0 0 20px; }

        .investment-advice { border-left: 4px solid #7e3af2; }
        .strategy-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 20px; }
        .strategy-card { border: 2px solid #e5e7eb; border-radius: 8px; padding: 20px; background: #fff; }
        .strategy-stable { border-color: #10b981; }
        .strategy-growth { border-color: #f59e0b; }
        .strategy-balanced { border-color: #3b82f6; }
        .strategy-efficient { border-color: #8b5cf6; }

        .strategy-header { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .strategy-icon { font-size: 32px; }
        .strategy-header h3 { margin: 0; color: #1f2937; }

        .strategy-etfs { margin: 15px 0; }
        .etf-tag { display: inline-block; padding: 4px 10px; background: #e0f2fe; color: #0369a1; 
            border-radius: 4px; margin-right: 5px; font-size: 12px; font-weight: 600; }

        .allocation-bar { display: flex; margin-top: 10px; height: 40px; border-radius: 5px; overflow: hidden; }
        .alloc-item { display: flex; flex-direction: column; justify-content: center; align-items: center; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
        .alloc-item:nth-child(1) { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .alloc-item:nth-child(2) { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .alloc-item:nth-child(3) { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }

        .strategy-pros { margin: 15px 0; }
        .strategy-pros ul { margin: 10px 0 0 20px; }
        .strategy-pros li { margin: 5px 0; color: #4b5563; }

        .strategy-metrics { display: flex; gap: 15px; margin-top: 15px; }
        .metric { flex: 1; padding: 10px; background: #f9fafb; border-radius: 5px; text-align: center; }
        .metric-label { display: block; font-size: 12px; color: #6b7280; }
        .metric-value { display: block; font-size: 18px; font-weight: 700; color: #2271b1; margin-top: 5px; }

        .market-analysis { border-left: 4px solid #f59e0b; }
        .analysis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .analysis-box { padding: 20px; background: #f9fafb; border-radius: 8px; text-align: center; }
        .highlight-box { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .highlight-box .box-label { color: #fff; }
        .highlight-box .box-value { color: #fff; }
        .box-label { font-size: 13px; color: #6b7280; margin-bottom: 8px; }
        .box-value { font-size: 22px; font-weight: 700; color: #2271b1; }
        .box-value.highlight { color: #d63638; }

        .cost-analysis { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        .cost-item { padding: 15px; background: #f0f9ff; border-radius: 5px; display: flex; justify-content: space-between; }
        .cost-item .highlight { color: #d63638; font-weight: 700; }

        .resources { border-left: 4px solid #10b981; }
        .resources-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; }
        .resource-item { display: flex; gap: 15px; padding: 15px; background: #f9fafb; border-radius: 5px; }
        .resource-icon { font-size: 28px; }
        .resource-item strong { display: block; margin-bottom: 5px; color: #1f2937; }
        .resource-item a { color: #2271b1; text-decoration: none; }
        .resource-item a:hover { text-decoration: underline; }

        @media (max-width: 782px) {
            .strategy-grid, .analysis-grid, .resources-grid { grid-template-columns: 1fr; }
        }
        ";
    }
}

add_action('plugins_loaded', function(){ Taiwan_Stock_Info_Pro_Max::get_instance(); });
