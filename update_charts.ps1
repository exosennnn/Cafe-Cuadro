
$files = @(
  "modules\employee\dashboard.php",
  "modules\employee_manager\reports.php",
  "modules\hr_manager\dashboard.php",
  "modules\hr_manager\recruitment.php",
  "modules\hr_staff\dashboard.php",
  "modules\hr_staff\my_attendance.php",
  "modules\hr_staff\reports.php",
  "modules\owner\analytics.php",
  "modules\owner\dashboard.php"
)

foreach ($file in $files) {
    $path = Join-Path "c:\xampp\htdocs\unified" $file
    if (Test-Path $path) {
        $content = Get-Content $path -Raw
        
        # Replace Primary Blue
        $content = $content -replace "#0d6efd", "#5e6b46"
        $content = $content -replace "rgba\(13,\s*110,\s*253", "rgba(94, 107, 70"
        
        # Replace Primary Blue variant (for hover/borders)
        $content = $content -replace "#0b5ed7", "#4b5538"
        
        # Additional primary blue replacements
        $content = $content -replace "rgba\(13,110,253", "rgba(94,107,70"

        Set-Content -Path $path -Value $content
        Write-Host "Updated $file"
    }
}

