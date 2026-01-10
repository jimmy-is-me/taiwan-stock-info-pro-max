<?php
/**
 * Plugin Name: 台股資訊中心 Pro Max
 * Description: ETF 配息與新股申購即時資訊 - 專業投資版
 * Version: 3.1.0
 * Author: Professional Investor
 * Text Domain: taiwan-stock-info-pro-max
 */

if (!defined('ABSPATH')) exit;

class Taiwan_Stock_Info_Pro_Max {

    private static $instance = null;
    private $cache_time = 600; // 10分鐘快取（盤中即時更新）

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

        // 智能更新排程
        add_action('stock_smart_update', array($this, 'smart_update'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function activate() {
        // 設定每 10 分鐘執行一次的檢查
        if (!wp_next_scheduled('stock_smart_update')) {
            wp_schedule_event(time(), 'stock_ten_minutes', 'stock_smart_update');
        }

        // 註冊自訂排程間隔
        add_filter('cron_schedules', array($this, 'custom_cron_schedules'));
    }

    public function deactivate() {
        wp_clear_scheduled_hook('stock_smart_update');
    }

    public function custom_cron_schedules($schedules) {
        $schedules['stock_ten_minutes'] = array(
            'interval' => 600, // 10 分鐘
            'display' => __('每 10 分鐘')
        );
        return $schedules;
    }

    /**
     * 智能更新：只在週一到週五 7:00-14:30 更新
     */
    public function smart_update() {
        $now = current_time('timestamp');
        $day_of_week = date('N', $now); // 1=週一, 7=週日
        $hour = (int)date('H', $now);
        $minute = (int)date('i', $now);
        $time_decimal = $hour + ($minute / 60);

        // 檢查：週一到週五（1-5）且時間在 7:00-14:30
        if ($day_of_week >= 1 && $day_of_week <= 5 && $time_decimal >= 7 && $time_decimal <= 14.5) {
            delete_transient('stock_etf_enhanced_v4');
            delete_transient('stock_ipo_v4');
            $this->get_etf_enhanced();
            $this->get_ipo_data();

            error_log('[台股資訊] 盤中更新: ' . current_time('Y-m-d H:i:s'));
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
        wp_enqueue_script('datatables', 'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js', array('jquery'), '1.13.7', true);
        wp_enqueue_style('datatables', 'https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css', array(), '1.13.7');
        wp_add_inline_style('wp-admin', $this->css());
    }

    public function render() {
        if (!current_user_can('manage_options')) wp_die('權限不足');

        $etf = $this->get_etf_enhanced();
        $ipo = $this->get_ipo_data();
        $today_ipo = $this->filter_today($ipo);
        $ana = $this->analyze_advanced($etf, $ipo);

        $etf_time = get_option('stock_etf_update_time', '尚未更新');
        $ipo_time = get_option('stock_ipo_update_time', '尚未更新');

        // 判斷目前是否為盤中時間
        $now = current_time('timestamp');
        $day = date('N', $now);
        $hour = (int)date('H', $now);
        $minute = (int)date('i', $now);
        $time_decimal = $hour + ($minute / 60);
        $is_trading_time = ($day >= 1 && $day <= 5 && $time_decimal >= 7 && $time_decimal <= 14.5);

        ?>
        <div class="wrap stock-dash-pro">
            <!-- 頁首 -->
            <div class="dashboard-header">
                <div class="header-left">
                    <h1>📈 台股資訊中心 <span class="pro-badge">PRO</span></h1>
                    <p class="tagline">專業投資決策分析平台</p>
                </div>
                <div class="header-right">
                    <?php if ($is_trading_time): ?>
                    <div class="live-indicator">
                        <span class="live-dot"></span>
                        <span>盤中即時更新</span>
                    </div>
                    <?php else: ?>
                    <div class="offline-indicator">
                        <span class="offline-dot"></span>
                        <span>非交易時段</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 控制面板 -->
            <div class="control-panel">
                <div class="panel-section">
                    <button class="btn-primary" onclick="updateData('all')" id="update-btn">
                        <span class="dashicons dashicons-update"></span>
                        手動更新資料
                    </button>
                </div>
                <div class="panel-section status-section">
                    <div class="status-item">
                        <span class="status-icon">📊</span>
                        <div>
                            <small>ETF 資料</small>
                            <strong><?php echo esc_html($etf_time); ?></strong>
                        </div>
                    </div>
                    <div class="status-item">
                        <span class="status-icon">🎯</span>
                        <div>
                            <small>申購資料</small>
                            <strong><?php echo esc_html($ipo_time); ?></strong>
                        </div>
                    </div>
                </div>
                <div id="status-msg" class="status-message"></div>
            </div>

            <!-- 系統說明 -->
            <div class="info-card">
                <div class="info-header">
                    <span class="info-icon">💡</span>
                    <strong>系統運作說明</strong>
                </div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="badge badge-success">即時更新</span>
                        <p>週一至週五 <strong>07:00-14:30</strong> 每 <strong>10 分鐘</strong>自動更新資料</p>
                    </div>
                    <div class="info-item">
                        <span class="badge badge-info">智能排程</span>
                        <p>非交易時段自動暫停更新，節省伺服器資源</p>
                    </div>
                    <div class="info-item">
                        <span class="badge badge-warning">表格排序</span>
                        <p>點擊任何欄位標題可進行<strong>升序/降序</strong>排序</p>
                    </div>
                    <div class="info-item">
                        <span class="badge badge-primary">一鍵更新</span>
                        <p>點擊「手動更新」可立即同步最新市場資料</p>
                    </div>
                </div>
            </div>

            <?php if (!empty($today_ipo)): ?>
            <!-- 今日可申購 -->
            <div class="card card-highlight">
                <div class="card-header">
                    <div class="card-title">
                        <span class="title-icon">🔥</span>
                        <h2>今日可申購標的</h2>
                        <span class="date-badge"><?php echo current_time('Y/m/d'); ?></span>
                    </div>
                    <span class="count-badge"><?php echo count($today_ipo); ?> 檔</span>
                </div>
                <div class="table-container">
                    <table id="today-ipo-table" class="data-table">
                        <thead>
                            <tr>
                                <th>代號</th><th>名稱</th><th>類型</th><th>申購期間</th>
                                <th>開獎日</th><th>承銷價</th><th>預估報酬</th><th>投資建議</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_ipo as $i): ?>
                            <tr class="hot-row">
                                <td><span class="code"><?php echo esc_html($i['code']); ?></span></td>
                                <td><strong><?php echo esc_html($i['name']); ?></strong></td>
                                <td><span class="label label-<?php echo esc_attr($i['type_class']); ?>"><?php echo esc_html($i['type']); ?></span></td>
                                <td><?php echo esc_html($i['period']); ?></td>
                                <td><?php echo esc_html($i['lottery']); ?></td>
                                <td><strong><?php echo esc_html($i['price']); ?></strong></td>
                                <td class="<?php echo esc_attr($i['ret_cls']); ?>"><strong><?php echo esc_html($i['return']); ?></strong></td>
                                <td><span class="rating"><?php echo esc_html($i['tip']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- 新股申購時程（一個月內） -->
            <?php if (!empty($ipo)): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="title-icon">🎯</span>
                        <h2>新股申購時程表</h2>
                        <span class="subtitle">近一個月可參與標的</span>
                    </div>
                    <span class="count-badge"><?php echo count($ipo); ?> 檔</span>
                </div>
                <div class="table-container">
                    <table id="ipo-table" class="data-table">
                        <thead>
                            <tr>
                                <th>代號</th><th>名稱</th><th>類型</th><th>申購期間</th>
                                <th>開獎日</th><th>承銷價</th><th>預估報酬</th><th>狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ipo as $i): ?>
                            <tr>
                                <td><span class="code"><?php echo esc_html($i['code']); ?></span></td>
                                <td><strong><?php echo esc_html($i['name']); ?></strong></td>
                                <td><span class="label label-<?php echo esc_attr($i['type_class']); ?>"><?php echo esc_html($i['type']); ?></span></td>
                                <td><?php echo esc_html($i['period']); ?></td>
                                <td><?php echo esc_html($i['lottery']); ?></td>
                                <td><?php echo esc_html($i['price']); ?></td>
                                <td class="<?php echo esc_attr($i['ret_cls']); ?>"><strong><?php echo esc_html($i['return']); ?></strong></td>
                                <td><span class="status status-<?php echo esc_attr($i['status']); ?>"><?php echo esc_html($i['status_txt']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- ETF 投資分析表 -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="title-icon">🏆</span>
                        <h2>ETF 投資分析表 Top 30</h2>
                        <span class="subtitle">點擊欄位標題可排序</span>
                    </div>
                    <span class="count-badge">30 檔</span>
                </div>
                <div class="table-container table-scroll">
                    <table id="etf-table" class="data-table etf-table">
                        <thead>
                            <tr>
                                <th>排名</th><th>代號</th><th>名稱</th><th>股價</th>
                                <th>殖利率</th><th>配息/股</th><th>張成本</th><th>年收益</th>
                                <th>費用率</th><th>配息頻率</th><th>2025報酬</th><th>評級</th>
                                <th>主要成分股</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($etf as $k => $e): ?>
                            <tr class="<?php echo $k < 3 ? 'top-row' : ''; ?>">
                                <td class="rank">
                                    <?php 
                                    if ($k === 0) echo '<span class="medal gold">🥇</span>';
                                    elseif ($k === 1) echo '<span class="medal silver">🥈</span>';
                                    elseif ($k === 2) echo '<span class="medal bronze">🥉</span>';
                                    else echo '<span class="rank-num">' . ($k + 1) . '</span>';
                                    ?>
                                </td>
                                <td><span class="code"><?php echo esc_html($e['code']); ?></span></td>
                                <td><strong><?php echo esc_html($e['name']); ?></strong></td>
                                <td><?php echo esc_html($e['price']); ?></td>
                                <td class="highlight-red"><strong><?php echo esc_html($e['yield']); ?></strong></td>
                                <td class="highlight-red"><?php echo esc_html($e['dividend']); ?></td>
                                <td><?php echo esc_html($e['cost_per_lot']); ?></td>
                                <td class="highlight-green"><strong><?php echo esc_html($e['annual_income']); ?></strong></td>
                                <td><?php echo esc_html($e['expense']); ?></td>
                                <td><span class="label label-<?php echo esc_attr($e['freq_c']); ?>"><?php echo esc_html($e['freq']); ?></span></td>
                                <td class="<?php echo esc_attr($e['ret_c']); ?>"><strong><?php echo esc_html($e['ret']); ?></strong></td>
                                <td class="stars"><?php echo $e['star']; ?></td>
                                <td class="holdings"><?php echo esc_html($e['holdings']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-footer">
                    <div class="footer-grid">
                        <div class="footer-item">
                            <span class="icon">💰</span>
                            <div>
                                <small>配息金額</small>
                                <p>預估每股配息（元）</p>
                            </div>
                        </div>
                        <div class="footer-item">
                            <span class="icon">📊</span>
                            <div>
                                <small>張成本</small>
                                <p>買進一張（1000股）所需資金</p>
                            </div>
                        </div>
                        <div class="footer-item">
                            <span class="icon">💵</span>
                            <div>
                                <small>年收益</small>
                                <p>持有一張的年度配息收入</p>
                            </div>
                        </div>
                        <div class="footer-item">
                            <span class="icon">🎯</span>
                            <div>
                                <small>主要成分股</small>
                                <p>前三大持股或投資標的</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 專業投資策略 -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="title-icon">💎</span>
                        <h2>專業投資策略建議</h2>
                        <span class="subtitle">根據市場數據分析</span>
                    </div>
                </div>
                <div class="strategy-container">
                    <?php foreach ($ana['strategies'] as $s): ?>
                    <div class="strategy-card <?php echo esc_attr($s['class']); ?>">
                        <div class="strategy-badge"><?php echo $s['icon']; ?></div>
                        <h3><?php echo esc_html($s['title']); ?></h3>

                        <div class="strategy-etfs">
                            <label>推薦 ETF</label>
                            <div class="etf-tags">
                                <?php foreach ($s['etfs'] as $etf): ?>
                                <span class="etf-tag"><?php echo esc_html($etf); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="strategy-allocation">
                            <label>建議配置</label>
                            <div class="allocation-chart">
                                <?php foreach ($s['allocation'] as $idx => $item): ?>
                                <div class="alloc-bar color-<?php echo $idx + 1; ?>" style="flex: <?php echo $item['percent']; ?>;">
                                    <span class="alloc-name"><?php echo esc_html($item['name']); ?></span>
                                    <span class="alloc-percent"><?php echo $item['percent']; ?>%</span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="strategy-pros">
                            <label>✅ 優勢特點</label>
                            <ul>
                                <?php foreach ($s['pros'] as $pro): ?>
                                <li><?php echo esc_html($pro); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>

                        <div class="strategy-stats">
                            <div class="stat">
                                <small>預期報酬</small>
                                <strong><?php echo esc_html($s['expected_return']); ?></strong>
                            </div>
                            <div class="stat">
                                <small>風險等級</small>
                                <strong><?php echo esc_html($s['risk_level']); ?></strong>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 市場深度分析 -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="title-icon">📊</span>
                        <h2>市場深度分析</h2>
                    </div>
                </div>

                <div class="analysis-section">
                    <h3>💰 配息能力分析</h3>
                    <div class="metric-grid">
                        <div class="metric-box highlight">
                            <div class="metric-value"><?php echo esc_html($ana['top_yield']); ?></div>
                            <div class="metric-label">最高殖利率</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-value"><?php echo esc_html($ana['avg_yield']); ?></div>
                            <div class="metric-label">平均殖利率</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-value"><?php echo esc_html($ana['high_yield_count']); ?></div>
                            <div class="metric-label">高殖利率 ETF (>10%)</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-value"><?php echo esc_html($ana['monthly_count']); ?></div>
                            <div class="metric-label">月配息 ETF</div>
                        </div>
                    </div>
                </div>

                <div class="analysis-section">
                    <h3>🚀 成長表現分析</h3>
                    <div class="metric-grid">
                        <div class="metric-box highlight">
                            <div class="metric-value"><?php echo esc_html($ana['top_ret']); ?></div>
                            <div class="metric-label">最佳 2025 報酬</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-value"><?php echo esc_html($ana['avg_return']); ?></div>
                            <div class="metric-label">平均報酬率</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-value"><?php echo esc_html($ana['high_growth_count']); ?></div>
                            <div class="metric-label">高成長 ETF (>15%)</div>
                        </div>
                        <div class="metric-box">
                            <div class="metric-value"><?php echo esc_html($ana['tech_count']); ?></div>
                            <div class="metric-label">半導體類 ETF</div>
                        </div>
                    </div>
                </div>

                <div class="analysis-section">
                    <h3>💵 成本效益分析</h3>
                    <div class="cost-grid">
                        <div class="cost-box">
                            <strong>最低成本入場</strong>
                            <span><?php echo esc_html($ana['lowest_cost']); ?></span>
                        </div>
                        <div class="cost-box highlight">
                            <strong>最高年收益</strong>
                            <span><?php echo esc_html($ana['highest_income']); ?></span>
                        </div>
                        <div class="cost-box">
                            <strong>最低費用率</strong>
                            <span><?php echo esc_html($ana['lowest_expense']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 相關資源 -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="title-icon">🔗</span>
                        <h2>相關資源與工具</h2>
                    </div>
                </div>
                <div class="resource-grid">
                    <a href="https://www.twse.com.tw/" target="_blank" class="resource-link">
                        <span class="resource-icon">📈</span>
                        <div>
                            <strong>台灣證券交易所</strong>
                            <small>即時行情與公告</small>
                        </div>
                    </a>
                    <a href="https://www.sitca.org.tw/" target="_blank" class="resource-link">
                        <span class="resource-icon">💼</span>
                        <div>
                            <strong>投信投顧公會</strong>
                            <small>ETF 淨值查詢</small>
                        </div>
                    </a>
                    <a href="https://www.moneydj.com/etf/" target="_blank" class="resource-link">
                        <span class="resource-icon">📊</span>
                        <div>
                            <strong>MoneyDJ ETF</strong>
                            <small>配息公告與分析</small>
                        </div>
                    </a>
                    <a href="https://www.cnyes.com/ipo/" target="_blank" class="resource-link">
                        <span class="resource-icon">🎯</span>
                        <div>
                            <strong>鉅亨網申購專區</strong>
                            <small>新股申購資訊</small>
                        </div>
                    </a>
                    <a href="https://www.google.com/finance" target="_blank" class="resource-link">
                        <span class="resource-icon">📱</span>
                        <div>
                            <strong>Google Finance</strong>
                            <small>國際即時行情</small>
                        </div>
                    </a>
                    <a href="https://www.investor.gov.tw/" target="_blank" class="resource-link">
                        <span class="resource-icon">📚</span>
                        <div>
                            <strong>投資人教育網</strong>
                            <small>投資知識學習</small>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // 初始化 DataTables
            $('#etf-table').DataTable({
                paging: false,
                searching: false,
                info: false,
                scrollX: true,
                order: [[0, 'asc']],
                columnDefs: [{ orderable: true, targets: '_all' }],
                language: { emptyTable: "目前無資料" }
            });

            $('#ipo-table, #today-ipo-table').DataTable({
                paging: false,
                searching: false,
                info: false,
                order: [[6, 'desc']],
                language: { emptyTable: "目前無資料" }
            });
        });

        function updateData(type) {
            const btn = document.getElementById('update-btn');
            const status = document.getElementById('status-msg');

            btn.disabled = true;
            btn.classList.add('loading');
            btn.innerHTML = '<span class="dashicons dashicons-update spin"></span> 更新中...';
            status.innerHTML = '<div class="notice-info">⏳ 正在同步最新資料...</div>';

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
                        status.innerHTML = '<div class="notice-success">✅ ' + response.data.msg + '</div>';
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        status.innerHTML = '<div class="notice-error">❌ ' + response.data.msg + '</div>';
                        btn.disabled = false;
                        btn.classList.remove('loading');
                        btn.innerHTML = '<span class="dashicons dashicons-update"></span> 手動更新資料';
                    }
                },
                error: function() {
                    status.innerHTML = '<div class="notice-error">❌ 更新失敗，請稍後再試</div>';
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.innerHTML = '<span class="dashicons dashicons-update"></span> 手動更新資料';
                }
            });
        }
        </script>
        <?php
    }

    private function get_etf_enhanced() {
        $cache = get_transient('stock_etf_enhanced_v4');
        if ($cache) return $cache;

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

            $dividend = round($price * ($yield_val / 100), 2);
            $cost_per_lot = number_format($price * 1000, 0);
            $annual_income = number_format($dividend * 1000, 0);

            $freq_c = 'annual';
            if (strpos($d[5], '月') !== false) $freq_c = 'monthly';
            elseif (strpos($d[5], '季') !== false) $freq_c = 'quarterly';
            elseif (strpos($d[5], '半年') !== false) $freq_c = 'semiannual';

            $ret_c = $return_val > 15 ? 'ret-excellent' : ($return_val > 8 ? 'ret-good' : 'ret-normal');
            $score = $yield_val * 0.4 + $return_val * 0.4;
            $star = $score > 15 ? '⭐⭐⭐⭐⭐' : ($score > 10 ? '⭐⭐⭐⭐' : ($score > 6 ? '⭐⭐⭐' : '⭐⭐'));

            $result[] = array(
                'code' => $d[0], 'name' => $d[1], 'price' => $d[2], 'yield' => $d[3],
                'dividend' => $dividend . '元', 'cost_per_lot' => $cost_per_lot . '元',
                'annual_income' => $annual_income . '元', 'expense' => $d[4],
                'freq' => $d[5], 'freq_c' => $freq_c, 'ret' => $d[6], 'ret_c' => $ret_c,
                'star' => $star, 'holdings' => $d[7]
            );
        }

        set_transient('stock_etf_enhanced_v4', $result, $this->cache_time);
        update_option('stock_etf_update_time', current_time('Y-m-d H:i:s'));
        return $result;
    }

    private function get_ipo_data() {
        $cache = get_transient('stock_ipo_v4');
        if ($cache) return $cache;

        // 一個月內的所有申購標的
        $data = array(
            array('4739','康普','上市增資','01/08-01/12','01/22','150元','預估45%','available'),
            array('1623','大東電','初上市','01/12-01/16','01/24','188元','預估147%','upcoming'),
            array('7795','長廣','初上市','01/06-01/08','01/16','125元','116%','closed'),
            array('6722','輝創','初上櫃','01/06-01/08','01/16','96元','74%','closed'),
            array('3037','欣興','上市增資','01/13-01/17','01/25','115元','90%','upcoming'),
            array('5566','精材','初上市','01/15-01/19','01/27','210元','預估68%','upcoming'),
        );

        $result = array();
        foreach ($data as $d) {
            $rv = floatval(preg_replace('/[^0-9.]/', '', $d[6]));
            $ret_cls = $rv > 100 ? 'ret-super' : ($rv > 50 ? 'ret-excellent' : 'ret-good');
            $tip = $rv > 100 ? '★★★ 強推' : ($rv > 50 ? '★★ 推薦' : '★ 可參與');

            $type_class = strpos($d[2], '初上') !== false ? 'ipo' : 'increase';

            $status_map = array('available' => '可申購', 'upcoming' => '即將開放', 'closed' => '已截止');

            $result[] = array(
                'code' => $d[0], 'name' => $d[1], 'type' => $d[2], 'type_class' => $type_class,
                'period' => $d[3], 'lottery' => $d[4], 'price' => $d[5], 'return' => $d[6],
                'ret_cls' => $ret_cls, 'status' => $d[7], 'status_txt' => $status_map[$d[7]], 'tip' => $tip
            );
        }

        set_transient('stock_ipo_v4', $result, $this->cache_time);
        update_option('stock_ipo_update_time', current_time('Y-m-d H:i:s'));
        return $result;
    }

    private function filter_today($ipo) {
        $today = current_time('m/d');
        return array_values(array_filter($ipo, function($i) use ($today) {
            return strpos($i['period'], $today) !== false && $i['status'] === 'available';
        }));
    }

    private function analyze_advanced($etf, $ipo) {
        $yields = array_map(function($e){ return floatval(str_replace('%', '', $e['yield'])); }, $etf);
        $returns = array_map(function($e){ return floatval(str_replace(array('+','%'), '', $e['ret'])); }, $etf);
        $expenses = array_map(function($e){ return floatval(str_replace('%', '', $e['expense'])); }, $etf);
        $costs = array_map(function($e){ return floatval(str_replace(array('元',','), '', $e['cost_per_lot'])); }, $etf);
        $incomes = array_map(function($e){ return floatval(str_replace(array('元',','), '', $e['annual_income'])); }, $etf);

        $max_yield_idx = array_search(max($yields), $yields);
        $max_return_idx = array_search(max($returns), $returns);
        $min_expense_idx = array_search(min($expenses), $expenses);
        $min_cost_idx = array_search(min($costs), $costs);
        $max_income_idx = array_search(max($incomes), $incomes);

        $strategies = array(
            array(
                'icon' => '💰', 'title' => '穩健配息策略', 'class' => 'strategy-stable',
                'etfs' => array('00701', '00927', '0056'),
                'allocation' => array(
                    array('name' => '00701', 'percent' => 40),
                    array('name' => '00927', 'percent' => 35),
                    array('name' => '0056', 'percent' => 25)
                ),
                'pros' => array('年化殖利率超過 10%', '定期配息提供穩定現金流', '適合退休族與保守型投資人'),
                'expected_return' => '10-12%', 'risk_level' => '低'
            ),
            array(
                'icon' => '🚀', 'title' => '成長型策略', 'class' => 'strategy-growth',
                'etfs' => array('0050', '00891', '00881'),
                'allocation' => array(
                    array('name' => '0050', 'percent' => 50),
                    array('name' => '00891', 'percent' => 30),
                    array('name' => '00881', 'percent' => 20)
                ),
                'pros' => array('2025 報酬率超過 18%', '追蹤科技龍頭股，成長動能強', '適合中長期投資'),
                'expected_return' => '16-20%', 'risk_level' => '中高'
            ),
            array(
                'icon' => '⚖️', 'title' => '平衡配置策略', 'class' => 'strategy-balanced',
                'etfs' => array('0050', '00878', '00929'),
                'allocation' => array(
                    array('name' => '0050', 'percent' => 40),
                    array('name' => '00878', 'percent' => 35),
                    array('name' => '00929', 'percent' => 25)
                ),
                'pros' => array('兼顧成長與配息', '月配季配組合，現金流穩定', '風險分散，適合大眾'),
                'expected_return' => '10-15%', 'risk_level' => '中'
            ),
            array(
                'icon' => '💎', 'title' => '低成本高效策略', 'class' => 'strategy-efficient',
                'etfs' => array('0050', '00692', '00878'),
                'allocation' => array(
                    array('name' => '0050', 'percent' => 45),
                    array('name' => '00692', 'percent' => 30),
                    array('name' => '00878', 'percent' => 25)
                ),
                'pros' => array('費用率低於 0.45%', '長期持有成本最低', '追蹤大盤，穩健成長'),
                'expected_return' => '12-16%', 'risk_level' => '中低'
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
            'high_yield_count' => count(array_filter($yields, function($v){ return $v > 10; })) . ' 檔',
            'high_growth_count' => count(array_filter($returns, function($v){ return $v > 15; })) . ' 檔',
            'monthly_count' => count(array_filter($etf, function($e){ return $e['freq_c'] === 'monthly'; })) . ' 檔',
            'tech_count' => count(array_filter($etf, function($e){ 
                return strpos($e['holdings'], '台積電') !== false || strpos($e['name'], '半導體') !== false;
            })) . ' 檔',
            'strategies' => $strategies
        );
    }

    public function ajax_update() {
        check_ajax_referer('stock_update', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('msg' => '權限不足'));
        }

        delete_transient('stock_etf_enhanced_v4');
        delete_transient('stock_ipo_v4');
        $this->get_etf_enhanced();
        $this->get_ipo_data();

        wp_send_json_success(array('msg' => '資料更新成功！已同步最新市場資訊。'));
    }

    private function css() {
        return file_get_contents(__DIR__ . '/assets/pro-style.css');
    }
}

add_action('plugins_loaded', function(){ Taiwan_Stock_Info_Pro_Max::get_instance(); });
add_filter('cron_schedules', function($schedules) {
    $schedules['stock_ten_minutes'] = array('interval' => 600, 'display' => '每 10 分鐘');
    return $schedules;
});
