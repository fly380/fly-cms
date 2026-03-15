# update_release.ps1
# Updates repo and creates v2.8.0-AI release on GitHub
# Run in D:\GitHub\fly-cms

cd D:\GitHub\fly-cms

Write-Host "=== fly-CMS v2.8.0-AI release ===" -ForegroundColor Cyan

# 1. Stage all changed files
git add .
git status --short

# 2. Commit if there are changes
$changes = git status --porcelain
if ($changes) {
    $msg = "feat: v2.8.0-AI - plugin system, MySQL fix, XAMPP fix, install.php fix"
    Write-Host ""
    Write-Host "Commit message: $msg"
    Write-Host "Press Enter to confirm or type a new one:"
    $custom = Read-Host
    if (-not [string]::IsNullOrWhiteSpace($custom)) { $msg = $custom }
    git commit -m $msg
    Write-Host "[OK] Committed" -ForegroundColor Green
} else {
    Write-Host "[--] No changes to commit" -ForegroundColor Yellow
}

# 3. Push commits to main
git push origin main
Write-Host "[OK] Pushed to main" -ForegroundColor Green

# 4. Remove old tags if they exist
Write-Host ""
Write-Host "Cleaning up old tags..." -ForegroundColor Cyan
git tag -d v2.7.0-AI 2>$null
git push origin :refs/tags/v2.7.0-AI 2>$null
git tag -d v2.8.0-AI 2>$null
git push origin :refs/tags/v2.8.0-AI 2>$null
Write-Host "[OK] Old tags removed" -ForegroundColor Green

# 5. Create new tag v2.8.0-AI
git tag v2.8.0-AI
git push origin v2.8.0-AI
Write-Host "[OK] Tag v2.8.0-AI pushed" -ForegroundColor Green

Write-Host ""
Write-Host "[OK] Done!" -ForegroundColor Green
Write-Host ""
Write-Host "  Next step - create release on GitHub:" -ForegroundColor Cyan
Write-Host "  https://github.com/fly380/fly-cms/releases/new" -ForegroundColor White
Write-Host ""
Write-Host "  Tag:   v2.8.0-AI" -ForegroundColor Yellow
Write-Host "  Title: fly-CMS v2.8.0-AI" -ForegroundColor Yellow
Write-Host ""
Write-Host "  Description:" -ForegroundColor Yellow
Write-Host "  ## What's new" -ForegroundColor White
Write-Host "  - Plugin system: hook architecture, manager UI, ZIP install" -ForegroundColor White
Write-Host "  - Fixed MySQL support in fly_db() (config.php)" -ForegroundColor White
Write-Host "  - Fixed FLY_ROOT for XAMPP/shared hosting (__DIR__)" -ForegroundColor White
Write-Host "  - Fixed mysql_schema.sql parser in install.php (users table)" -ForegroundColor White
Write-Host "  - Two demo plugins: ukr-to-lat, seo-meta" -ForegroundColor White