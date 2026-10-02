$phpFiles = Get-ChildItem -Path 'c:\xampp\htdocs\unified' -Filter '*.php' -Recurse
foreach ($file in $phpFiles) {
    $content = Get-Content -Raw -Encoding UTF8 $file.FullName
    $newContent = [regex]::Replace($content, 'href="https://fonts\.googleapis\.com/css2\?family=[^"]*"', 'href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"')
    if ($content -ne $newContent) {
        Set-Content -Path $file.FullName -Value $newContent -Encoding UTF8
        Write-Host "Updated PHP: $($file.FullName)"
    }
}

$cssFiles = Get-ChildItem -Path 'c:\xampp\htdocs\unified\assets' -Filter '*.css' -Recurse
foreach ($file in $cssFiles) {
    $content = Get-Content -Raw -Encoding UTF8 $file.FullName
    $newContent = $content -replace "'DM Sans'", "'Poppins'"
    $newContent = $newContent -replace '"DM Sans"', '"Poppins"'
    $newContent = $newContent -replace "'Plus Jakarta Sans'", "'Poppins'"
    $newContent = $newContent -replace '"Plus Jakarta Sans"', '"Poppins"'
    $newContent = $newContent -replace "'Fraunces'", "'Poppins'"
    $newContent = $newContent -replace '"Fraunces"', '"Poppins"'
    $newContent = $newContent -replace "'Inter'", "'Poppins'"
    $newContent = $newContent -replace '"Inter"', '"Poppins"'
    if ($content -ne $newContent) {
        Set-Content -Path $file.FullName -Value $newContent -Encoding UTF8
        Write-Host "Updated CSS: $($file.FullName)"
    }
}
