# 快速上传到 GitHub

## 📦 项目文件

你的项目包含以下文件：
- `taiwan-stock-info-pro-max.php` - WordPress 插件主文件
- `README.md` - 项目说明文档
- `.gitignore` - Git 忽略文件
- `GITHUB_UPLOAD_GUIDE.md` - 详细上传指南

## 🚀 最简单的方式：GitHub 网页界面

### 步骤：

1. **打开浏览器，访问：** https://github.com/new

2. **创建新仓库：**
   - Repository name: `taiwan-stock-info-pro-max`（或你喜欢的名称）
   - Description: `WordPress 插件 - 台股資訊中心 Pro Max`
   - 选择 Public 或 Private
   - ✅ 可以勾选 "Add a README file"
   - 点击 "Create repository"

3. **上传文件：**
   - 在仓库页面，点击 "Add file" 按钮
   - 选择 "Upload files"
   - 将以下文件拖拽到页面：
     ```
     taiwan-stock-info-pro-max.php
     README.md
     .gitignore
     ```
   - 在页面底部输入提交信息：`Initial commit: 台股資訊中心 Pro Max`
   - 点击 "Commit changes"

完成！🎉

## 💻 如果已安装 Git

在项目目录打开 PowerShell 或命令提示符，运行：

```bash
git init
git add .
git commit -m "Initial commit: 台股資訊中心 Pro Max"

# 替换 YOUR_USERNAME 和 REPO_NAME
git remote add origin https://github.com/YOUR_USERNAME/REPO_NAME.git
git branch -M main
git push -u origin main
```

## 📥 安装 Git（如果还没有）

访问：https://git-scm.com/download/win
下载并安装 Git for Windows

## ❓ 需要帮助？

告诉我你的 GitHub 用户名和想要的仓库名称，我可以帮你生成完整的命令！
