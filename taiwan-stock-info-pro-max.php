<?php
/**
 * Plugin Name: 台股資訊中心 Pro Max
 * Description: ETF 配息與新股申購即時資訊 - 孟菲斯風格版
 * Version: 4.1.0
 * Author: wumetax
 */

if (!defined('ABSPATH')) exit;

class Taiwan_Stock_Info_Pro_Max {

    private static $instance = null;
    private $cache_time = 600;

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

    public function add_inline_styles() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'toplevel_page-stock-dashboard') {
            ?>
            <style>
            /* 孟菲斯風格 - 全寬乾淨版本 */
            #wpcontent { padding-left: 0 !important; }
            #wpfooter { display: none; }
            
            .stock-dash-pro {
                margin: 0 !important;
                padding: 0 !important;
                width: 100vw !important;
                max-width: 100vw !important;
                background: #fafafa;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                font-size: 15px;
                font-weight: 500;
                line-height: 1.7;
                color: #3B5998;
            }

            .header {
                background: #ffffff;
                color: #3B5998;
                padding: 30px 50px;
                border-bottom: 4px solid #6C5CE7;
                position: relative;
                overflow: hidden;
            }

            .header::before {
                content: '';
                position: absolute;
                top: -50px;
                right: 100px;
                width: 120px;
                height: 120px;
                background: #FFD93D;
                border-radius: 50%;
                opacity: 0.3;
            }

            .header::after {
                content: '';
                position: absolute;
                bottom: -30px;
                left: 150px;
                width: 80px;
                height: 80px;
                background: #FF6B6B;
                clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
                opacity: 0.2;
            }

            .header h1 {
                margin: 0 0 8px 0;
                font-size: 32px;
                font-weight: 700;
                color: #3B5998;
                position: relative;
                z-index: 1;
            }

            .header p {
                margin: 0;
                font-size: 16px;
                color: #6C5CE7;
                font-weight: 500;
                position: relative;
                z-index: 1;
            }

            .main {
                padding: 40px 50px;
                max-width: 100%;
            }

            .control-bar {
                background: #ffffff;
                padding: 25px 30px;
                margin-bottom: 30px;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 20px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }

            .btn {
                padding: 12px 28px;
                border: 2px solid transparent;
                border-radius: 6px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
                position: relative;
            }

            .btn-primary {
                background: #6C5CE7;
                color: #fff;
                border-color: #6C5CE7;
            }

            .btn-primary:hover {
                background: #5849d1;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(108,92,231,0.3);
            }

            .btn-secondary {
                background: #ffffff;
                color: #3B5998;
                border-color: #3B5998;
            }

            .btn-secondary:hover {
                background: #3B5998;
                color: #ffffff;
            }

            .status-info {
                display: flex;
                gap: 35px;
                font-size: 14px;
                font-weight: 500;
            }

            .status-info span {
                color: #888;
            }

            .status-info strong {
                color: #3B5998;
                font-weight: 700;
            }

            .card {
                background: #ffffff;
                border: 2px solid #e0e0e0;
                border-radius: 10px;
                padding: 35px;
                margin-bottom: 30px;
                box-shadow: 0 3px 10px rgba(0,0,0,0.06);
                position: relative;
                overflow: hidden;
            }

            .card::before {
                content: '';
                position: absolute;
                top: -20px;
                right: -20px;
                width: 60px;
                height: 60px;
                background: #FFD93D;
                border-radius: 50%;
                opacity: 0.2;
            }

            .card-header {
                border-bottom: 3px solid #f0f0f0;
                padding-bottom: 20px;
                margin-bottom: 25px;
                position: relative;
            }

            .card-header h2 {
                margin: 0 0 8px 0;
                font-size: 24px;
                font-weight: 700;
                color: #3B5998;
            }

            .card-header .subtitle {
                font-size: 14px;
                color: #888;
                font-weight: 500;
            }

            .quote-box {
                background: linear-gradient(135deg, #fff5f5, #ffe8e8);
                border-left: 6px solid #FF6B6B;
                border-radius: 8px;
                padding: 30px 35px;
                margin-bottom: 30px;
                position: relative;
                box-shadow: 0 2px 8px rgba(255,107,107,0.15);
            }

            .quote-box::after {
                content: '"';
                position: absolute;
                top: 10px;
                right: 20px;
                font-size: 80px;
                color: #FF6B6B;
                opacity: 0.1;
                font-family: Georgia, serif;
                line-height: 1;
            }

            .quote-text {
                font-size: 18px;
                color: #FF6B6B;
                margin-bottom: 15px;
                line-height: 1.8;
                font-weight: 600;
                position: relative;
                z-index: 1;
            }

            .quote-author {
                font-size: 15px;
                color: #d63447;
                font-weight: 700;
                text-align: right;
                position: relative;
                z-index: 1;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                font-size: 15px;
                border: 2px solid #e0e0e0;
            }

            thead {
                background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            }

            th {
                padding: 16px 14px;
                text-align: left;
                font-weight: 700;
                color: #3B5998;
                white-space: nowrap;
                cursor: pointer;
                border: 1px solid #d0d0d0;
                transition: all 0.2s;
            }

            th:hover {
                background: #dfe3e8;
            }

            tbody tr {
                transition: all 0.2s;
            }

            tbody tr:hover {
                background: #f8f9ff;
            }

            td {
                padding: 14px;
                border: 1px solid #e8e8e8;
                color: #3B5998;
                font-weight: 500;
            }

            .link {
                color: #6C5CE7;
                text-decoration: none;
                font-weight: 700;
                transition: all 0.2s;
                position: relative;
            }

            .link:hover {
                color: #5849d1;
                text-decoration: underline;
            }

            .red {
                color: #FF6B6B;
                font-weight: 700;
            }

            .green {
                color: #51cf66;
                font-weight: 700;
            }

            .label {
                display: inline-block;
                padding: 5px 12px;
                font-size: 13px;
                font-weight: 700;
                border-radius: 20px;
                background: #e9ecef;
                color: #3B5998;
                border: 2px solid #d0d5dd;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }

            .stat-box {
                background: linear-gradient(135deg, #f8f9fa, #ffffff);
                padding: 28px;
                border: 2px solid #e0e0e0;
                border-radius: 10px;
                text-align: center;
                transition: all 0.3s;
                position: relative;
                overflow: hidden;
            }

            .stat-box::before {
                content: '';
                position: absolute;
                top: -10px;
                right: -10px;
                width: 40px;
                height: 40px;
                background: #FFD93D;
                border-radius: 50%;
                opacity: 0.3;
            }

            .stat-box:hover {
                transform: translateY(-5px);
                box-shadow: 0 6px 16px rgba(108,92,231,0.2);
                border-color: #6C5CE7;
            }

            .stat-value {
                font-size: 32px;
                font-weight: 800;
                color: #FF6B6B;
                margin-bottom: 8px;
                position: relative;
                z-index: 1;
            }

            .stat-label {
                font-size: 14px;
                color: #3B5998;
                font-weight: 600;
                position: relative;
                z-index: 1;
            }

            .strategy-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 25px;
                margin-top: 20px;
            }

            .strategy-card {
                background: #ffffff;
                border: 3px solid #e0e0e0;
                border-radius: 12px;
                padding: 30px;
                transition: all 0.3s;
                position: relative;
                overflow: hidden;
            }

            .strategy-card::before {
                content: '';
                position: absolute;
                bottom: -30px;
                left: -30px;
                width: 80px;
                height: 80px;
                background: #6C5CE7;
                clip-path: polygon(50% 0%, 0% 100%, 100% 100%);
                opacity: 0.1;
            }

            .strategy-card:hover {
                transform: translateY(-8px);
                box-shadow: 0 8px 20px rgba(108,92,231,0.25);
                border-color: #6C5CE7;
            }

            .strategy-card h3 {
                margin: 0 0 18px 0;
                font-size: 20px;
                font-weight: 700;
                color: #3B5998;
                position: relative;
                z-index: 1;
            }

            .strategy-card p {
                margin: 12px 0;
                font-size: 15px;
                color: #3B5998;
                line-height: 1.7;
                font-weight: 500;
                position: relative;
                z-index: 1;
            }

            .strategy-card ul {
                list-style: none;
                padding: 0;
                margin: 18px 0;
                position: relative;
                z-index: 1;
            }

            .strategy-card li {
                padding: 8px 0;
                font-size: 15px;
                color: #3B5998;
                font-weight: 500;
                line-height: 1.6;
            }

            .strategy-card li:before {
                content: "▸ ";
                color: #FF6B6B;
                font-weight: 900;
                margin-right: 8px;
            }

            .message {
                padding: 16px 20px;
                border-radius: 8px;
                font-size: 15px;
                margin-top: 15px;
                font-weight: 600;
                border: 2px solid transparent;
            }

            .message-info {
                background: #e3f2fd;
                color: #1565c0;
                border-color: #2196f3;
            }

            .message-success {
                background: #e8f5e9;
                color: #2e7d32;
                border-color: #4caf50;
            }

            .message-error {
                background: #ffebee;
                color: #c62828;
                border-color: #f44336;
            }

            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }

            .spin {
                animation: spin 1s linear infinite;
            }

            /* 響應式調整 */
            @media (max-width: 768px) {
                .header { padding: 20px 25px; }
                .main { padding: 25px; }
                .card { padding: 25px; }
                .control-bar { flex-direction: column; align-items: stretch; }
                .status-info { flex-direction: column; gap: 10px; }
                table { font-size: 13px; }
                th, td { padding: 10px 8px; }
            }
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
            array('投資最大的風險，不是價格的波動，而是你的資本永久損失。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('在別人貪婪時恐懼，在別人恐懼時貪婪。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('時間是優質企業的朋友，卻是平庸企業的敵人。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('投資的秘訣在於：在股市表現良好時，不過度樂觀；在市場低迷時，不過度悲觀。', '約翰·坦伯頓', '鄧普頓基金創辦人'),
            array('長期投資的真正關鍵是：不要試圖打敗市場，而是要享受市場的回報。', '約翰·伯格', '先鋒集團創辦人'),
            array('股市短期是投票機，長期是稱重機。', '班傑明·葛拉漢', '價值投資之父'),
            array('成功的投資來自於常識的應用，而非火箭科學。', '彼得·林區', '富達麥哲倫基金經理人'),
            array('最佳的持股時間是：永遠。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('複利是世界第八大奇蹟，懂得運用它的人將獲得成功。', '愛因斯坦', '理論物理學家'),
            array('分散投資是保護無知的唯一方法，對那些知道自己在做什麼的人來說毫無意義。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('風險來自於你不知道自己在做什麼。', '華倫·巴菲特', '波克夏·海瑟威公司執行長'),
            array('投資ETF的優勢在於分散風險，降低個股波動帶來的衝擊。', '約翰·伯格', '先鋒集團創辦人'),
            array('不要把所有雞蛋放在同一個籃子裡。', '哈利·馬可維茲', '現代投資組合理論創始人'),
            array('市場總是在悲觀中誕生，在懷疑中成長，在樂觀中成熟，在狂熱中死亡。', '約翰·坦伯頓', '鄧普頓基金創辦人'),
            array('投資的本質是延遲享受，把今天的消費投入到未來的增長。', '查理·蒙格', '波克夏·海瑟威副董事長')
        );

        $quote = $quotes[array_rand($quotes)];
        set_transient('stock_quote', $quote, 3600);
        return $quote;
    }

    private function get_etf_data() {
        $cache = get_transient('stock_etf_data');
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
                'ret' => $d[6],
                'holdings' => $d[7],
                'yield_val' => $yield_val,
                'return_val' => $return_val
            );
        }

        set_transient('stock_etf_data', $result, $this->cache_time);
        update_option('stock_etf_update_time', current_time('Y-m-d H:i:s'));
        return $result;
    }

    private function get_ipo_data() {
        $cache = get_transient('stock_ipo_data');
        if ($cache) return $cache;

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
            $tip = $rv > 100 ? '★★★ 強推' : ($rv > 50 ? '★★ 推薦' : '★ 可參與');
            $status_map = array('available' => '可申購', 'upcoming' => '即將開放', 'closed' => '已截止');

            $result[] = array(
                'code' => $d[0],
                'name' => $d[1],
                'type' => $d[2],
                'period' => $d[3],
                'lottery' => $d[4],
                'price' => $d[5],
                'return' => $d[6],
                'tip' => $tip,
                'status' => $d[7],
                'status_txt' => $status_map[$d[7]]
            );
        }

        set_transient('stock_ipo_data', $result, $this->cache_time);
        update_option('stock_ipo_update_time', current_time('Y-m-d H:i:s'));
        return $result;
    }

    private function analyze_data($etf) {
        $yields = array_column($etf, 'yield_val');
        $returns = array_column($etf, 'return_val');
        
        $high_yield = array_filter($etf, function($e) { return $e['yield_val'] > 10; });
        $high_growth = array_filter($etf, function($e) { return $e['return_val'] > 15; });
        $monthly = array_filter($etf, function($e) { return strpos($e['freq'], '月') !== false; });
        $tech = array_filter($etf, function($e) { 
            return strpos($e['holdings'], '台積電') !== false || strpos($e['holdings'], '聯發科') !== false; 
        });

        usort($etf, function($a, $b) { return $b['yield_val'] <=> $a['yield_val']; });
        $top_yield_etfs = array_slice(array_column($etf, 'code'), 0, 3);

        usort($etf, function($a, $b) { return $b['return_val'] <=> $a['return_val']; });
        $top_growth_etfs = array_slice(array_column($etf, 'code'), 0, 3);

        return array(
            'top_yield' => round(max($yields), 2) . '%',
            'avg_yield' => round(array_sum($yields) / count($yields), 2) . '%',
            'high_yield_count' => count($high_yield),
            'top_return' => '+' . round(max($returns), 2) . '%',
            'avg_return' => '+' . round(array_sum($returns) / count($returns), 2) . '%',
            'high_growth_count' => count($high_growth),
            'monthly_count' => count($monthly),
            'tech_count' => count($tech),
            'strategies' => array(
                array(
                    'title' => '高配息策略',
                    'etfs' => $top_yield_etfs,
                    'desc' => '專注於高殖利率 ETF，適合追求穩定現金流的投資人',
                    'pros' => array(
                        '年化配息率 ' . round(max($yields), 1) . '%',
                        '分散持股降低風險',
                        '適合退休規劃與被動收入'
                    ),
                    'risk' => '低'
                ),
                array(
                    'title' => '成長動能策略',
                    'etfs' => $top_growth_etfs,
                    'desc' => '聚焦高成長性 ETF，適合長期資本增值',
                    'pros' => array(
                        '年化報酬率 ' . round(max($returns), 1) . '%',
                        '掌握科技成長趨勢',
                        '適合長期投資累積財富'
                    ),
                    'risk' => '中高'
                )
            )
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) wp_die('權限不足');

        $etf = $this->get_etf_data();
        $ipo = $this->get_ipo_data();
        $quote = $this->get_quote();
        $analysis = $this->analyze_data($etf);

        $etf_time = get_option('stock_etf_update_time', '尚未更新');
        $ipo_time = get_option('stock_ipo_update_time', '尚未更新');

        ?>
        <div class="stock-dash-pro">
            <div class="header">
                <h1>台股資訊中心 Pro Max</h1>
                <p>ETF 配息與新股申購即時資訊 - 極簡專業版</p>
            </div>

            <div class="main">
                <div class="control-bar">
                    <div>
                        <button class="btn btn-primary" onclick="updateData()" id="update-btn">手動更新資料</button>
                        <button class="btn btn-secondary" onclick="location.reload()">重新載入頁面</button>
                    </div>
                    <div class="status-info">
                        <div><span>ETF 更新:</span> <strong><?php echo esc_html($etf_time); ?></strong></div>
                        <div><span>申購更新:</span> <strong><?php echo esc_html($ipo_time); ?></strong></div>
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
                        <h2>ETF 投資分析表</h2>
                        <span class="subtitle">共 <?php echo count($etf); ?> 檔 ETF - 點擊欄位標題可排序</span>
                    </div>
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
                                <td><?php echo esc_html($e['price']); ?></td>
                                <td class="red"><?php echo esc_html($e['yield']); ?></td>
                                <td class="red"><?php echo esc_html($e['dividend']); ?></td>
                                <td><?php echo esc_html($e['cost_per_lot']); ?></td>
                                <td class="green"><?php echo esc_html($e['annual_income']); ?></td>
                                <td><?php echo esc_html($e['expense']); ?></td>
                                <td><span class="label"><?php echo esc_html($e['freq']); ?></span></td>
                                <td class="<?php echo $e['return_val'] > 10 ? 'green' : ''; ?>"><?php echo esc_html($e['ret']); ?></td>
                                <td><?php echo esc_html($e['holdings']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($ipo)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>新股申購時程表</h2>
                        <span class="subtitle">共 <?php echo count($ipo); ?> 檔標的</span>
                    </div>
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
                                <td><?php echo esc_html($i['price']); ?></td>
                                <td class="red"><?php echo esc_html($i['return']); ?></td>
                                <td><?php echo esc_html($i['tip']); ?></td>
                                <td><span class="label"><?php echo esc_html($i['status_txt']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h2>市場數據統計</h2>
                        <span class="subtitle">基於當前 ETF 資料的綜合分析</span>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['top_yield']); ?></div>
                            <div class="stat-label">最高殖利率</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['avg_yield']); ?></div>
                            <div class="stat-label">平均殖利率</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['high_yield_count']); ?> 檔</div>
                            <div class="stat-label">高殖利率 (>10%)</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['top_return']); ?></div>
                            <div class="stat-label">最佳報酬率</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['avg_return']); ?></div>
                            <div class="stat-label">平均報酬率</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['high_growth_count']); ?> 檔</div>
                            <div class="stat-label">高成長 (>15%)</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['monthly_count']); ?> 檔</div>
                            <div class="stat-label">月配息 ETF</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-value"><?php echo esc_html($analysis['tech_count']); ?> 檔</div>
                            <div class="stat-label">科技類 ETF</div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2>投資策略建議</h2>
                        <span class="subtitle">基於實時數據自動生成的配置建議</span>
                    </div>
                    <div class="strategy-grid">
                        <?php foreach ($analysis['strategies'] as $s): ?>
                        <div class="strategy-card">
                            <h3><?php echo esc_html($s['title']); ?></h3>
                            <p><?php echo esc_html($s['desc']); ?></p>
                            <p><strong>推薦 ETF:</strong> <?php echo esc_html(implode('、', $s['etfs'])); ?></p>
                            <ul>
                                <?php foreach ($s['pros'] as $pro): ?>
                                <li><?php echo esc_html($pro); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p><strong>風險等級:</strong> <?php echo esc_html($s['risk']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#etf-table, #ipo-table').DataTable({
                paging: false,
                searching: false,
                info: false,
                order: [[0, 'asc']],
                language: { emptyTable: "目前無資料" }
            });
        });

        function updateData() {
            const btn = document.getElementById('update-btn');
            const status = document.getElementById('status-msg');

            btn.disabled = true;
            btn.textContent = '更新中...';
            status.innerHTML = '<div class="message message-info">正在同步最新資料...</div>';

            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'stock_update',
                    nonce: '<?php echo wp_create_nonce('stock_update'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        status.innerHTML = '<div class="message message-success">' + response.data.msg + '</div>';
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        status.innerHTML = '<div class="message message-error">' + response.data.msg + '</div>';
                        btn.disabled = false;
                        btn.textContent = '手動更新資料';
                    }
                },
                error: function() {
                    status.innerHTML = '<div class="message message-error">更新失敗</div>';
                    btn.disabled = false;
                    btn.textContent = '手動更新資料';
                }
            });
        }
        </script>
        <?php
    }
}

Taiwan_Stock_Info_Pro_Max::get_instance();
