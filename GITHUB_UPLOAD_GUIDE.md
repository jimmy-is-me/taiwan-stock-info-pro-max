# GitHub 上传指南

## 方式一：使用 Git 命令行（推荐）

### 1. 安装 Git

如果还没有安装 Git，请访问：https://git-scm.com/download/win
下载并安装 Git for Windows。

### 2. 初始化 Git 仓库

在项目目录中打开 PowerShell 或命令提示符，执行：

```bash
git init
git add .
git commit -m "Initial commit: 台股資訊中心 Pro Max WordPress 插件"
```

### 3. 在 GitHub 创建仓库

1. 登录 GitHub (https://github.com)
2. 点击右上角的 "+" 号，选择 "New repository"
3. 输入仓库名称（例如：`taiwan-stock-info-pro-max`）
4. 选择 Public 或 Private
5. **不要**勾选 "Initialize this repository with a README"
6. 点击 "Create repository"

### 4. 连接到 GitHub 并推送

```bash
# 添加远程仓库（将 YOUR_USERNAME 和 REPO_NAME 替换为你的信息）
git remote add origin https://github.com/YOUR_USERNAME/REPO_NAME.git

# 推送代码
git branch -M main
git push -u origin main
```

如果使用 SSH：
```bash
git remote add origin git@github.com:YOUR_USERNAME/REPO_NAME.git
git branch -M main
git push -u origin main
```

## 方式二：使用 GitHub 网页界面（简单快速）

### 1. 创建仓库

1. 登录 GitHub
2. 点击右上角的 "+" 号，选择 "New repository"
3. 输入仓库名称（例如：`taiwan-stock-info-pro-max`）
4. 选择 Public 或 Private
5. **勾选** "Initialize this repository with a README"
6. 点击 "Create repository"

### 2. 上传文件

1. 在仓库页面点击 "Add file" > "Upload files"
2. 将以下文件拖拽到页面：
   - `taiwan-stock-info-pro-max.php`
   - `README.md`
   - `.gitignore`
3. 在页面底部输入提交信息（例如："Initial commit: 台股資訊中心 Pro Max"）
4. 点击 "Commit changes"

## 方式三：使用 GitHub Desktop（图形界面）

### 1. 安装 GitHub Desktop

下载地址：https://desktop.github.com/

### 2. 使用步骤

1. 打开 GitHub Desktop
2. 点击 "File" > "Add Local Repository"
3. 选择项目文件夹
4. 如果还没有创建 GitHub 仓库，点击 "Publish repository"
5. 输入仓库名称和描述
6. 选择 Public 或 Private
7. 点击 "Publish Repository"

## 文件说明

上传到 GitHub 的文件包括：
- `taiwan-stock-info-pro-max.php` - 主插件文件
- `README.md` - 项目说明文档
- `.gitignore` - Git 忽略文件配置
- `GITHUB_UPLOAD_GUIDE.md` - 本上传指南（可选）

## 注意事项

- 确保不要上传敏感信息（如数据库密码、API 密钥等）
- `.gitignore` 文件已配置好，会自动忽略不必要的文件
- 如果使用命令行，首次推送可能需要输入 GitHub 用户名和密码（或使用 Personal Access Token）

## 后续更新

如果使用 Git 命令行，后续更新代码：

```bash
git add .
git commit -m "更新说明"
git push
```
