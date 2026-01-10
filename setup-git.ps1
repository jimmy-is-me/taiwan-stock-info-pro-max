# Git 安装和仓库初始化脚本

Write-Host "=== 台股資訊中心 Pro Max - Git 设置脚本 ===" -ForegroundColor Cyan
Write-Host ""

# 检查 Git 是否已安装
try {
    $gitVersion = git --version 2>&1
    Write-Host "✓ Git 已安装: $gitVersion" -ForegroundColor Green
    $gitInstalled = $true
} catch {
    Write-Host "✗ Git 未安装" -ForegroundColor Yellow
    $gitInstalled = $false
}

if (-not $gitInstalled) {
    Write-Host ""
    Write-Host "需要安装 Git 才能上传到 GitHub" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "请选择安装方式：" -ForegroundColor Cyan
    Write-Host "1. 自动下载并安装 Git for Windows"
    Write-Host "2. 手动安装（打开下载页面）"
    Write-Host "3. 跳过安装，使用 GitHub 网页界面上传"
    Write-Host ""
    $choice = Read-Host "请输入选项 (1/2/3)"
    
    if ($choice -eq "1") {
        Write-Host "正在下载 Git for Windows..." -ForegroundColor Cyan
        $gitInstallerUrl = "https://github.com/git-for-windows/git/releases/download/v2.43.0.windows.1/Git-2.43.0-64-bit.exe"
        $installerPath = "$env:TEMP\GitInstaller.exe"
        
        try {
            Invoke-WebRequest -Uri $gitInstallerUrl -OutFile $installerPath
            Write-Host "下载完成！正在启动安装程序..." -ForegroundColor Green
            Start-Process -FilePath $installerPath -Wait
            Write-Host "安装完成后，请重新运行此脚本" -ForegroundColor Yellow
            exit
        } catch {
            Write-Host "自动下载失败，请手动安装" -ForegroundColor Red
            Start-Process "https://git-scm.com/download/win"
        }
    } elseif ($choice -eq "2") {
        Write-Host "正在打开 Git 下载页面..." -ForegroundColor Cyan
        Start-Process "https://git-scm.com/download/win"
        Write-Host "安装完成后，请重新运行此脚本" -ForegroundColor Yellow
        exit
    } else {
        Write-Host ""
        Write-Host "=== 使用 GitHub 网页界面上传 ===" -ForegroundColor Cyan
        Write-Host "1. 访问 https://github.com/new 创建新仓库"
        Write-Host "2. 点击 'Add file' > 'Upload files'"
        Write-Host "3. 上传以下文件："
        Get-ChildItem -File | ForEach-Object { Write-Host "   - $($_.Name)" }
        Write-Host ""
        exit
    }
}

# Git 已安装，初始化仓库
Write-Host ""
Write-Host "=== 初始化 Git 仓库 ===" -ForegroundColor Cyan

if (Test-Path ".git") {
    Write-Host "✓ Git 仓库已存在" -ForegroundColor Green
} else {
    Write-Host "正在初始化 Git 仓库..." -ForegroundColor Cyan
    git init
    Write-Host "✓ 仓库初始化完成" -ForegroundColor Green
}

Write-Host ""
Write-Host "正在添加文件..." -ForegroundColor Cyan
git add .
Write-Host "✓ 文件已添加" -ForegroundColor Green

Write-Host ""
Write-Host "正在提交..." -ForegroundColor Cyan
git commit -m "Initial commit: 台股資訊中心 Pro Max WordPress 插件"
Write-Host "✓ 提交完成" -ForegroundColor Green

Write-Host ""
Write-Host "=== 下一步操作 ===" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. 访问 https://github.com/new 创建新仓库"
Write-Host "2. 复制仓库 URL（例如：https://github.com/yourusername/taiwan-stock-info-pro-max.git）"
Write-Host "3. 运行以下命令连接并推送："
Write-Host ""
Write-Host "   git remote add origin YOUR_REPO_URL" -ForegroundColor Yellow
Write-Host "   git branch -M main" -ForegroundColor Yellow
Write-Host "   git push -u origin main" -ForegroundColor Yellow
Write-Host ""
Write-Host "或者告诉我你的 GitHub 用户名和仓库名，我可以帮你生成命令！" -ForegroundColor Green
