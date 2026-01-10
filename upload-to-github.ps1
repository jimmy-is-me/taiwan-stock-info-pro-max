# 上传到 GitHub 的简单脚本

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  台股資訊中心 Pro Max" -ForegroundColor Cyan
Write-Host "  GitHub 上传助手" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# 检查 Git
$gitAvailable = $false
try {
    $null = git --version 2>&1
    $gitAvailable = $true
    Write-Host "[OK] Git 已安装" -ForegroundColor Green
} catch {
    Write-Host "[!] Git 未安装" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "当前目录文件：" -ForegroundColor Cyan
Get-ChildItem -File | ForEach-Object { 
    Write-Host "  - $($_.Name)" -ForegroundColor White
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "上传方式选择：" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

if ($gitAvailable) {
    Write-Host "方式 1: 使用 Git 命令行（推荐）" -ForegroundColor Green
    Write-Host ""
    Write-Host "步骤：" -ForegroundColor Yellow
    Write-Host "1. 访问 https://github.com/new 创建新仓库"
    Write-Host "2. 不要勾选 'Initialize with README'"
    Write-Host "3. 复制仓库 URL"
    Write-Host "4. 运行以下命令：" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "   git init" -ForegroundColor White
    Write-Host "   git add ." -ForegroundColor White
    Write-Host "   git commit -m `"Initial commit`"" -ForegroundColor White
    Write-Host "   git remote add origin YOUR_REPO_URL" -ForegroundColor White
    Write-Host "   git branch -M main" -ForegroundColor White
    Write-Host "   git push -u origin main" -ForegroundColor White
    Write-Host ""
} else {
    Write-Host "方式 1: 安装 Git（推荐）" -ForegroundColor Green
    Write-Host "  下载地址: https://git-scm.com/download/win" -ForegroundColor Yellow
    Write-Host "  安装后重新运行此脚本" -ForegroundColor Yellow
    Write-Host ""
}

Write-Host "方式 2: 使用 GitHub 网页界面（最简单）" -ForegroundColor Green
Write-Host ""
Write-Host "步骤：" -ForegroundColor Yellow
Write-Host "1. 访问 https://github.com/new" -ForegroundColor Cyan
Write-Host "2. 输入仓库名称（例如：taiwan-stock-info-pro-max）"
Write-Host "3. 选择 Public 或 Private"
Write-Host "4. 可以勾选 'Initialize with README'"
Write-Host "5. 点击 'Create repository'"
Write-Host "6. 在仓库页面点击 'Add file' > 'Upload files'"
Write-Host "7. 拖拽上传以下文件：" -ForegroundColor Cyan
Get-ChildItem -File -Exclude "*.ps1" | ForEach-Object { 
    Write-Host "   - $($_.Name)" -ForegroundColor White
}
Write-Host "8. 输入提交信息：Initial commit: 台股資訊中心 Pro Max"
Write-Host "9. 点击 'Commit changes'"
Write-Host ""

Write-Host "方式 3: 使用 GitHub Desktop" -ForegroundColor Green
Write-Host "  下载地址: https://desktop.github.com/" -ForegroundColor Yellow
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "需要帮助？告诉我你的 GitHub 用户名和仓库名，" -ForegroundColor Green
Write-Host "我可以帮你生成完整的命令！" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
