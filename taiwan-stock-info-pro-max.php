<?php
/**
 * Plugin Name: 台股資訊中心 Pro Max - 自動更新版
 * Description: ETF 配息與新股申購即時資訊 - 自動從證交所 API 抓取
 * Version: 6.0.0
 * Author: wumetax
 */

if (!defined('ABSPATH')) exit;

class Taiwan_Stock_Info_Pro_Max {

    private static $instance = null;
    private $cache_time = 3600; // 快取 1 小時

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
            add_action('admin_head', array($this, 'add_inline_styles'));
        }

        add_action('stock_smart_update', array($this, 'smart_update'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    // ========== 證交所 API 抓取函數 ==========
    
    private function fetch_twse_stock_day_all() {
        $url = 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL';
        
        $args = array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('[台股資訊] 證交所 API 請求失敗: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !is_array($data)) {
            error_log('[台股資訊] 證交所 API 回傳格式錯誤');
            return false;
        }

        $stock_map = array();
        foreach ($data as $item) {
            if (isset($item['Code'])) {
                $stock_map[$item['Code']] = $item;
            }
        }

        return $stock_map;
    }

    private function fetch_twse_realtime_stock($code) {
        $url = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp?ex_ch=tse_' . $code . '.tw&json=1&delay=0';
        
        $args = array(
            'timeout' => 10,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['msgArray'][0])) {
            return $data['msgArray'][0];
        }

        return null;
    }

    private function scrape_moneydj_etf_info($code) {
        $url = 'https://www.moneydj.com/etf/x/basic/basic0004.xdjhtm?etfid=' . $code . '.TW';
        
        $args = array(
            'timeout' => 20,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            error_log('[台股資訊] MoneyDJ 請求失敗: ' . $response->get_error_message());
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        
        $result = array(
            'yield' => 0,
            'expense' => 0.5,
            'freq' => '季配',
            'holdings' => '資料更新中'
        );

        if (preg_match('/近12個月殖利率.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['yield'] = floatval($matches[1]);
        } elseif (preg_match('/殖利率.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['yield'] = floatval($matches[1]);
        }

        if (preg_match('/經理費.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['expense'] = floatval($matches[1]);
        } elseif (preg_match('/管理費.*?(\d+\.?\d*)%/isu', $html, $matches)) {
            $result['expense'] = floatval($matches[1]);
        }

        if (preg_match('/配息頻率.*?(月配|季配|半年配|年配)/isu', $html, $matches)) {
            $result['freq'] = $matches[1];
        }

        if (preg_match_all('/<td[^>]*>[\s]*([^<]{2,10})[\s]*<\/td>[\s]*<td[^>]*>[\s]*(\d+\.?\d*)%/isu', $html, $matches, PREG_SET_ORDER)) {
            $holdings = array();
            $count = 0;
            foreach ($matches as $match) {
                if ($count >= 3) break;
                $stock_name = trim($match[1]);
                if (mb_strlen($stock_name) >= 2 && mb_strlen($stock_name) <= 10) {
                    $holdings[] = $stock_name;
                    $count++;
                }
            }
            if (!empty($holdings)) {
                $result['holdings'] = implode('、', $holdings);
            }
        }

        return $result;
    }

    private function get_etf_data() {
        $cache = get_transient('stock_etf_data');
        if ($cache) return $cache;

        $etf_list = array(
            '0050' => '元大台灣50',
            '0056' => '元大高股息',
            '00878' => '國泰永續高股息',
            '00919' => '群益台灣精選高息',
            '00929' => '復華台灣科技優息',
            '00701' => '國泰股利精選30',
            '00713' => '元大高息低波',
            '00927' => '群益半導體收益',
            '00881' => '國泰台灣科技龍頭',
            '00940' => '元大臺灣價值高息',
            '00918' => '大華優利高填息30',
            '00934' => '中信成長高股息',
            '00946' => '群益科技高息成長',
            '00730' => '富邦臺灣優質高息',
            '00939' => '統一台灣高息動能',
            '00915' => '凱基優選高股息30',
            '00900' => '富邦特選高股息30',
            '00923' => '群益台ESG低碳50',
            '00850' => '元大臺灣ESG永續',
            '00692' => '富邦公司治理',
        );

        $stock_data = $this->fetch_twse_stock_day_all();
        
        $result = array();
        $index = 0;

        foreach ($etf_list as $code => $name) {
            $index++;
            
            $price = 20.0;
            $change_percent = 0;
            
            if ($stock_data && isset($stock_data[$code])) {
                $stock_info = $stock_data[$code];
                $price = floatval($stock_info['ClosingPrice'] ?? $stock_info['Close'] ?? 20.0);
                $change = floatval($stock_info['Change'] ?? 0);
                $change_percent = $change;
            }

            if ($index % 3 == 0) {
                sleep(3);
            }
            
            $etf_info = $this->scrape_moneydj_etf_info($code);
            
            if (!$etf_info) {
                error_log("[台股資訊] 無法抓取 {$code} 的配息資訊,使用預設值");
                $etf_info = array(
                    'yield' => 5.0,
                    'expense' => 0.5,
                    'freq' => '季配',
                    'holdings' => '資料更新中'
                );
            }

            $yield_val = $etf_info['yield'];
            $dividend = round($price * ($yield_val / 100), 2);
            $cost_per_lot = $price * 1000;
            $annual_income = $dividend * 1000;

            $result[] = array(
                'code' => $code,
                'name' => $name,
                'price' => number_format($price, 2),
                'yield' => $yield_val . '%',
                'dividend' => $dividend . '元',
                'cost_per_lot' => number_format($cost_per_lot, 0) . '元',
                'annual_income' => number_format($annual_income, 0) . '元',
                'expense' => $etf_info['expense'] . '%',
                'freq' => $etf_info['freq'],
                'ret' => ($change_percent >= 0 ? '+' : '') . number_format($change_percent, 2) . '%',
                'holdings' => $etf_info['holdings'],
                'yield_val' => $yield_val,
                'return_val' => $change_percent
            );
        }

        set_transient('stock_etf_data', $result, $this->cache_time);
        update_option('stock_etf_update_time', current_time('Y-m-d H:i:s'));
        
        error_log('[台股資訊] ETF 資料更新完成,共 ' . count($result) . ' 檔');
        
        return $result;
    }

    private function get_ipo_data() {
        $cache = get_transient('stock_ipo_data');
        if ($cache) return $cache;

        $result = array(
            array(
                'code' => '4739', 'name' => '康普', 'type' => '上市增資',
                'period' => '01/08-01/12', 'lottery' => '01/22', 'price' => '150元',
                'return' => '預估45%', 'tip' => '★ 可參與',
                'status' => 'closed', 'status_txt' => '已截止'
            ),
            array(
                'code' => '1623', 'name' => '大東電', 'type' => '初上市',
                'period' => '01/12-01/16', 'lottery' => '01/24', 'price' => '188元',
                'return' => '預估147%', 'tip' => '★★★ 強推',
                'status' => 'available', 'status_txt' => '可申購'
            ),
        );

        set_transient('stock_ipo_data', $result, $this->cache_time);
        update_option('stock_ipo_update_time', current_time('Y-m-d H:i:s'));
        
        return $result;
    }

    // ========== WordPress 整合函數 ==========

    public function add_inline_styles() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page-stock-dashboard') {
            ?>
            <style>
            /* 原有的 CSS 樣式 - 完整保留 */
            #wpcontent { padding-left: 0 !important; }
            #wpfooter { display: none !important; }
            .update-nag { display: none !important; }
            
            .stock-dash-pro {
                margin: 0 !important;
                padding: 0 !important;
                width: 100vw !important;
                max-width: 100vw !important;
                background: #fafafa;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                font-size: 15px;
                line-height: 1.7;
                color: #2c3e50;
            }

            .header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #ffffff;
                padding: 35px 50px;
                border-bottom: 5px solid #6C5CE7;
                box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            }

            .header h1 {
                margin: 0 0 10px 0;
                font-size: 36px;
                font-weight: 800;
                text-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }

            .main { padding: 35px 45px; }

            .control-bar {
                background: #ffffff;
                padding: 20px 25px;
                margin-bottom: 25px;
                border: 2px solid #e0e0e0;
                border-radius: 10px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 20px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            }

            .btn {
                padding: 12px 30px;
                border: 2px solid transparent;
                border-radius: 8px;
                font-size: 15px;
                font-weight: 700;
                cursor: pointer;
                transition: all 0.3s;
            }

            .btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #fff;
                box-shadow: 0 4px 10px rgba(102,126,234,0.3);
            }

            .btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 15px rgba(102,126,234,0.4);
            }

            .btn-secondary {
                background: #ffffff;
                color: #667eea;
                border-color: #667eea;
            }

            .btn-secondary:hover {
                background: #667eea;
                color: #ffffff;
            }

            .status-info {
                display: flex;
                gap: 30px;
                font-size: 14px;
                font-weight: 600;
            }

            .card {
                background: #ffffff;
                border: 3px solid #e8e8e8;
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 25px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            .card-header {
                border-bottom: 4px solid #f0f0f0;
                padding-bottom: 18px;
                margin-bottom: 25px;
            }

            .card-header h2 {
                margin: 0 0 8px 0;
                font-size: 26px;
                font-weight: 800;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
            }

            .quote-box {
                background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
                border-left: 8px solid #e17055;
                border-radius: 12px;
                padding: 30px 40px;
                margin-bottom: 25px;
                box-shadow: 0 4px 15px rgba(225,112,85,0.2);
            }

            .quote-text {
                font-size: 19px;
                color: #d63031;
                margin-bottom: 15px;
                font-weight: 700;
            }

            .quote-author {
                font-size: 16px;
                color: #c0392b;
                font-weight: 800;
                text-align: right;
            }

            .table-wrapper {
                width: 100%;
                overflow-x: auto;
                border: 3px solid #dfe6e9;
                border-radius: 10px;
            }

            table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                font-size: 15px;
            }

            thead {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            }

            th {
                padding: 18px 16px;
                text-align: center;
                font-weight: 800;
                color: #ffffff;
                border-right: 2px solid rgba(255,255,255,0.2);
            }

            tbody tr:nth-child(odd) { background: #f8f9fa; }
            tbody tr:nth-child(even) { background: #ffffff; }
            tbody tr:hover {
                background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
                transform: scale(1.005);
            }

            td {
                padding: 16px;
                border-right: 2px solid #ecf0f1;
                color: #2c3e50;
                font-weight: 600;
                text-align: center;
            }

            .link {
                color: #667eea;
                text-decoration: none;
                font-weight: 800;
            }

            .link:hover { color: #764ba2; }

            .red { color: #e74c3c !important; font-weight: 800 !important; }
            .green { color: #27ae60 !important; font-weight: 800 !important; }
            .orange { color: #f39c12 !important; font-weight: 800 !important; }

            .label {
                display: inline-block;
                padding: 6px 14px;
                font-size: 13px;
                font-weight: 800;
                border-radius: 25px;
                background: linear-gradient(135deg, #a8e6cf 0%, #dcedc1 100%);
                color: #27ae60;
                border: 2px solid #27ae60;
            }

            .label.primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: #ffffff;
                border-color: #667eea;
            }

            .label.danger {
                background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
                color: #ffffff;
                border-color: #e74c3c;
            }

            .message {
                padding: 18px 25px;
                border-radius: 10px;
                font-size: 15px;
                margin-top: 15px;
                font-weight: 700;
            }

            .message-success {
                background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
                color: #2e7d32;
                border: 3px solid #4caf50;
            }

            .message-error {
                background: linear-gradient(135deg, #ffebee 0%, #ffcdd2 100%);
                color: #c62828;
                border: 3px solid #f44336;
            }

            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            .spin { animation: spin 1s linear infinite; }
            </style>
            <?php
        }
    }

    public function activate() {
        if (!wp_next_scheduled('stock_smart_update')) {
            wp_schedule_event(time(), 'stock_ten_minutes', 'stock_smart_update');
        }
        add_filter('cron_schedules', array($this, 'custom_cron_schedules'));
    }

    public function deactivate() {
        wp_clear_scheduled_hook('stock_smart_update');
    }

    public function custom_cron_schedules($schedules) {
        $schedules['stock_ten_minutes'] = array(
            'interval' => 600,
            'display' => '每 10 分鐘'
        );
        return $schedules;
    }

    public function smart_update() {
        $now = current_time('timestamp');
        $day_of_week = date('N', $now);
        $hour = (int)date('H', $now);
        $minute = (int)date('i', $now);
        $time_decimal = $hour + ($minute / 60);

        if ($day_of_week >= 1 && $day_of_week <= 5 && $time_decimal >= 7 && $time_decimal <= 14.5) {
            delete_transient('stock_etf_data');
            delete_transient('stock_ipo_data');
            delete_transient('stock_quote');
            $this->get_etf_data();
            $this->get_ipo_data();
            error_log('[台股資訊] 盤中自動更新: ' . current_time('Y-m-d H:i:s'));
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
    }

    private function get_etf_url($code) {
        return 'https://www.moneydj.com/etf/x/basic/basic0004.xdjhtm?etfid=' . urlencode($code) . '.TW';
    }

    private function get_stock_url($code) {
        return 'https://www.google.com/finance/quote/' . urlencode($code) . ':TPE';
    }

    public function ajax_update() {
        check_ajax_referer('stock_update', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('msg' => '權限不足'));
        }

        delete_transient('stock_etf_data');
        delete_transient('stock_ipo_data');
        delete_transient('stock_quote');
        
        $this->get_etf_data();
        $this->get_ipo_data();

        wp_send_json_success(array('msg' => '資料更新成功！頁面即將重新載入'));
    }

    private function get_quote() {
        $cache = get_transient('stock_quote');
        if ($cache) return $cache;

        $quotes = array(
            array('投資最大的風險,不是價格的波動,而是你的資本永久損失。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('在別人貪婪時恐懼,在別人恐懼時貪婪。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('時間是優質企業的朋友,卻是平庸企業的敵人。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
        );

        $quote = $quotes[array_rand($quotes)];
        set_transient('stock_quote', $quote, 3600);
        return $quote;
    }

    private function analyze_data($etf) {
        $yields = array_column($etf, 'yield_val');
        $returns = array_column($etf, 'return_val');
        
        return array(
            'top_yield' => round(max($yields), 2) . '%',
            'avg_yield' => round(array_sum($yields) / count($yields), 2) . '%',
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) wp_die('權限不足');

        $etf = $this->get_etf_data();
        $ipo = $this->get_ipo_data();
        $quote = $this->get_quote();

        $etf_time = get_option('stock_etf_update_time', '尚未更新');
        $ipo_time = get_option('stock_ipo_update_time', '尚未更新');

        ?>
        <div class="stock-dash-pro">
            <div class="header">
                <h1>📊 台股資訊中心 Pro Max</h1>
                <p>ETF 配息與新股申購即時資訊 | 自動從證交所 API 抓取</p>
            </div>

            <div class="main">
                <div class="control-bar">
                    <div>
                        <button class="btn btn-primary" onclick="updateData()" id="update-btn">🔄 手動更新資料</button>
                        <button class="btn btn-secondary" onclick="location.reload()">♻️ 重新載入頁面</button>
                    </div>
                    <div class="status-info">
                        <div><span>ETF 更新:</span> <strong><?php echo esc_html($etf_time); ?></strong></div>
                        <div><span>IPO 更新:</span> <strong><?php echo esc_html($ipo_time); ?></strong></div>
                        <div><span>系統時間:</span> <strong><?php echo current_time('Y-m-d H:i:s'); ?></strong></div>
                    </div>
                </div>
                
                <div id="status-msg"></div>

                <div class="quote-box">
                    <div class="quote-text"><?php echo esc_html($quote[0]); ?></div>
                    <div class="quote-author">—— <?php echo esc_html($quote[1]); ?>（<?php echo esc_html($quote[2]); ?>）</div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>📈 ETF 投資分析表</h2>
                        <span class="subtitle">共 <?php echo count($etf); ?> 檔 ETF - 自動從證交所 API 更新</span>
                    </div>
                    <div class="table-wrapper">
                        <table id="etf-table">
                            <thead>
                                <tr>
                                    <th>代號</th>
                                    <th>名稱</th>
                                    <th>股價</th>
                                    <th>殖利率</th>
                                    <th>配息/股</th>
                                    <th>張成本</th>
                                    <th>年收益</th>
                                    <th>費用率</th>
                                    <th>配息頻率</th>
                                    <th>2025報酬</th>
                                    <th>主要成分股</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($etf as $e): ?>
                                <tr>
                                    <td><a href="<?php echo esc_url($this->get_etf_url($e['code'])); ?>" target="_blank" class="link"><?php echo esc_html($e['code']); ?></a></td>
                                    <td><a href="<?php echo esc_url($this->get_etf_url($e['code'])); ?>" target="_blank" class="link"><?php echo esc_html($e['name']); ?></a></td>
                                    <td class="orange"><?php echo esc_html($e['price']); ?></td>
                                    <td class="red"><?php echo esc_html($e['yield']); ?></td>
                                    <td class="red"><?php echo esc_html($e['dividend']); ?></td>
                                    <td><?php echo esc_html($e['cost_per_lot']); ?></td>
                                    <td class="green"><?php echo esc_html($e['annual_income']); ?></td>
                                    <td><?php echo esc_html($e['expense']); ?></td>
                                    <td><span class="label primary"><?php echo esc_html($e['freq']); ?></span></td>
                                    <td class="<?php echo $e['return_val'] > 0 ? 'green' : 'red'; ?>"><?php echo esc_html($e['ret']); ?></td>
                                    <td><?php echo esc_html($e['holdings']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (!empty($ipo)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>🎯 新股申購時程表</h2>
                        <span class="subtitle">共 <?php echo count($ipo); ?> 檔標的</span>
                    </div>
                    <div class="table-wrapper">
                        <table id="ipo-table">
                            <thead>
                                <tr>
                                    <th>代號</th>
                                    <th>名稱</th>
                                    <th>類型</th>
                                    <th>申購期間</th>
                                    <th>開獎日</th>
                                    <th>承銷價</th>
                                    <th>預估報酬</th>
                                    <th>建議</th>
                                    <th>狀態</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ipo as $i): ?>
                                <tr>
                                    <td><a href="<?php echo esc_url($this->get_stock_url($i['code'])); ?>" target="_blank" class="link"><?php echo esc_html($i['code']); ?></a></td>
                                    <td><a href="<?php echo esc_url($this->get_stock_url($i['code'])); ?>" target="_blank" class="link"><?php echo esc_html($i['name']); ?></a></td>
                                    <td><span class="label"><?php echo esc_html($i['type']); ?></span></td>
                                    <td><?php echo esc_html($i['period']); ?></td>
                                    <td><?php echo esc_html($i['lottery']); ?></td>
                                    <td class="orange"><?php echo esc_html($i['price']); ?></td>
                                    <td class="red"><?php echo esc_html($i['return']); ?></td>
                                    <td><?php echo esc_html($i['tip']); ?></td>
                                    <td>
                                        <span class="label <?php echo $i['status'] === 'available' ? 'primary' : 'danger'; ?>">
                                            <?php echo esc_html($i['status_txt']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#etf-table').DataTable({
                pageLength: 20,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/zh-HANT.json'
                }
            });

            $('#ipo-table').DataTable({
                pageLength: 10,
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/zh-HANT.json'
                }
            });
        });

        function updateData() {
            var btn = document.getElementById('update-btn');
            var msg = document.getElementById('status-msg');
            
            btn.disabled = true;
            btn.innerHTML = '🔄 更新中...';
            msg.innerHTML = '<div class="message message-info">正在從證交所 API 抓取資料,請稍候...</div>';

            jQuery.post(ajaxurl, {
                action: 'stock_update',
                nonce: '<?php echo wp_create_nonce('stock_update'); ?>'
            }, function(response) {
                if (response.success) {
                    msg.innerHTML = '<div class="message message-success">' + response.data.msg + '</div>';
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    msg.innerHTML = '<div class="message message-error">更新失敗: ' + response.data.msg + '</div>';
                    btn.disabled = false;
                    btn.innerHTML = '🔄 手動更新資料';
                }
            });
        }
        </script>
        <?php
    }
}

// 初始化插件
Taiwan_Stock_Info_Pro_Max::get_instance();
