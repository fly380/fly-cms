# update_release.ps1
# Updates local repo and re-releases v2.7.0-AI on GitHub
# Run in D:\GitHub\fly-cms

cd D:\GitHub\fly-cms

Write-Host "=== fly-CMS release update ===" -ForegroundColor Cyan

# 1. Stage all changed files
git add .
git status --short

# 2. Commit if there are changes
$changes = git status --porcelain
if ($changes) {
    $msg = "chore: update v2.7.0-AI release files"
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

# 3. Delete old tag locally and remotely
Write-Host ""
Write-Host "Removing old tag v2.7.0-AI..." -ForegroundColor Cyan
git tag -d v2.7.0-AI 2>$null
git push origin :refs/tags/v2.7.0-AI 2>$null
Write-Host "[OK] Old tag removed" -ForegroundColor Green

# 4. Create new tag on latest commit
git tag v2.7.0-AI
git push origin v2.7.0-AI
Write-Host "[OK] New tag v2.7.0-AI pushed" -ForegroundColor Green

# 5. Push commits
git push origin main

Write-Host ""
Write-Host "[OK] Done!" -ForegroundColor Green
Write-Host "     Now go to GitHub and update the release:" -ForegroundColor Cyan
Write-Host "     https://github.com/fly380/fly-cms/releases/tag/v2.7.0-AI" -ForegroundColor Cyan
Write-Host ""
Write-Host "     Click 'Edit release' and hit 'Update release'" -ForegroundColor Yellow
Write-Host "     GitHub will auto-generate a new ZIP from the updated tag." -ForegroundColor Yellow