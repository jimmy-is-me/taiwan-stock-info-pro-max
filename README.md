# 📊 台股資訊中心 Pro Max - 自動更新版

WordPress 外掛程式，自動從證交所 OpenAPI 抓取台股 ETF 即時資料，提供配息分析與新股申購資訊。

## ✨ 功能特色

### 🎯 核心功能
- **自動抓取數據**: 從證交所 OpenAPI 自動取得 20 檔熱門 ETF 即時股價
- **配息資訊**: 自動爬取殖利率、配息頻率、經理費等關鍵數據
- **智能更新**: 交易日盤中每 10 分鐘自動更新，非交易時段暫停
- **投資分析**: 自動計算年收益、張成本、報酬率等投資指標
- **新股申購**: 提供 IPO 申購時程與預估報酬率
- **孟菲斯風格介面**: 現代化設計，支援響應式排版

### 📈 數據來源
- **股價**: 證交所 OpenAPI (官方公開資料，完全合法) [web:12][web:14]
- **配息**: MoneyDJ ETF 資訊網 (網頁爬取，含延遲機制)
- **成分股**: 前三大持股自動解析

## 🚀 安裝說明

### 方法一：直接上傳
1. 下載外掛檔案 `taiwan-stock-info-pro-max.php`
2. 上傳到 `/wp-content/plugins/` 目錄
3. 在 WordPress 後台啟用外掛
4. 左側選單會出現「台股資訊」選項

### 方法二：FTP 上傳
```bash
# 連接到你的 WordPress 主機
cd /wp-content/plugins/
mkdir taiwan-stock-info-pro-max
cd taiwan-stock-info-pro-max

# 上傳 taiwan-stock-info-pro-max.php
# 啟用外掛
