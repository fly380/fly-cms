# github_setup.ps1
# Run in PowerShell in D:\GitHub\fly-cms
# Replace YOUR_USERNAME with your GitHub login

$GITHUB_USER = "YOUR_USERNAME"
$REPO_NAME   = "fly-cms"
$REMOTE_URL  = "https://github.com/$GITHUB_USER/$REPO_NAME.git"

Write-Host "=== fly-CMS GitHub Setup ===" -ForegroundColor Cyan

if (-not (Test-Path ".git")) {
    git init
    Write-Host "[OK] git init" -ForegroundColor Green
} else {
    Write-Host "[--] Already initialized" -ForegroundColor Yellow
}

if (-not (Test-Path ".gitignore")) {
    Write-Host "[!!] .gitignore not found" -ForegroundColor Red
    exit 1
}

if (Test-Path ".env") {
    $check = git check-ignore -q .env 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "[OK] .env is protected by .gitignore" -ForegroundColor Green
    } else {
        Write-Host "[!!] .env is NOT in .gitignore - aborting!" -ForegroundColor Red
        exit 1
    }
}

$existing = git remote get-url origin 2>&1
if ($LASTEXITCODE -ne 0) {
    git remote add origin $REMOTE_URL
    Write-Host "[OK] Remote added: $REMOTE_URL" -ForegroundColor Green
} else {
    Write-Host "[--] Remote exists: $existing" -ForegroundColor Yellow
}

git add .
git status --short

$msg = "feat: v2.7.0-AI - support tickets, SMTP helper, GitHub updater"
Write-Host ""
Write-Host "Commit message: $msg"
Write-Host "Press Enter to use it, or type a new one:"
$custom = Read-Host
if (-not [string]::IsNullOrWhiteSpace($custom)) { $msg = $custom }

git commit -m $msg
Write-Host "[OK] Committed" -ForegroundColor Green

git branch -M main
git push -u origin main

if ($LASTEXITCODE -eq 0) {
    Write-Host "[OK] Done: https://github.com/$GITHUB_USER/$REPO_NAME" -ForegroundColor Green
} else {
    Write-Host "[!!] Push failed. Steps to fix:" -ForegroundColor Red
    Write-Host "  1. Create empty repo at github.com/new (name: fly-cms, no README)"
    Write-Host "  2. Get Personal Access Token: github.com/settings/tokens/new (scope: repo)"
    Write-Host "  3. Run:"
    Write-Host "     git remote set-url origin https://YOUR_TOKEN@github.com/$GITHUB_USER/fly-cms.git"
    Write-Host "     git push -u origin main"
}