$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Runtime.WindowsRuntime | Out-Null

$asTaskOp = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
  $_.Name -eq 'AsTask' -and $_.IsGenericMethodDefinition -and $_.GetParameters().Count -eq 1 -and
  $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
} | Select-Object -First 1

$asTaskAction = [System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
  $_.Name -eq 'AsTask' -and -not $_.IsGenericMethodDefinition -and $_.GetParameters().Count -eq 1 -and
  $_.GetParameters()[0].ParameterType.FullName -like '*IAsyncAction*'
} | Select-Object -First 1

function Await-Op($op, [Type]$resultType) {
  $task = $asTaskOp.MakeGenericMethod($resultType).Invoke($null, @($op))
  return $task.GetAwaiter().GetResult()
}
function Await-Action($op) {
  $task = $asTaskAction.Invoke($null, @($op))
  $task.GetAwaiter().GetResult() | Out-Null
}

$root = 'c:\xampp\htdocs\tntt'
$srcDir = Get-ChildItem -LiteralPath $root -Directory | Where-Object {
  (Get-ChildItem -LiteralPath $_.FullName -File -EA SilentlyContinue | Where-Object { $_.Name -like 'Maria*' }).Count -gt 0
} | Select-Object -First 1
if (-not $srcDir) { throw 'anh folder not found' }
Write-Host "Dir=$($srcDir.FullName)"

$src = Get-ChildItem -LiteralPath $srcDir.FullName -File | Where-Object {
  $_.Name -like 'Anna*' -and [string]::IsNullOrEmpty($_.Extension)
} | Select-Object -First 1
if (-not $src) {
  $src = Get-ChildItem -LiteralPath $srcDir.FullName -File | Where-Object { $_.Name -like 'Anna*' } | Select-Object -First 1
}
if (-not $src) { throw 'Anna file not found' }

$heicPath = Join-Path $srcDir.FullName 'Anna_Bui_Thi_Khanh_Ly.heic'
Copy-Item -LiteralPath $src.FullName -Destination $heicPath -Force
Write-Host "HEIC=$heicPath"

$null = [Windows.Storage.StorageFile,Windows.Storage,ContentType=WindowsRuntime]
$null = [Windows.Storage.Streams.IRandomAccessStream,Windows.Storage.Streams,ContentType=WindowsRuntime]
$null = [Windows.Graphics.Imaging.BitmapDecoder,Windows.Graphics,ContentType=WindowsRuntime]
$null = [Windows.Graphics.Imaging.BitmapEncoder,Windows.Graphics,ContentType=WindowsRuntime]
$null = [Windows.Graphics.Imaging.SoftwareBitmap,Windows.Graphics,ContentType=WindowsRuntime]

try {
  $file = Await-Op ([Windows.Storage.StorageFile]::GetFileFromPathAsync($heicPath)) ([Windows.Storage.StorageFile])
  $stream = Await-Op ($file.OpenAsync([Windows.Storage.FileAccessMode]::Read)) ([Windows.Storage.Streams.IRandomAccessStream])
  $decoder = Await-Op ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
  $soft = Await-Op ($decoder.GetSoftwareBitmapAsync()) ([Windows.Graphics.Imaging.SoftwareBitmap])

  $tmpPath = Join-Path $env:TEMP ('anna_' + [guid]::NewGuid().ToString('N') + '.jpg')
  [IO.File]::WriteAllBytes($tmpPath, @(0))
  $outFile = Await-Op ([Windows.Storage.StorageFile]::GetFileFromPathAsync($tmpPath)) ([Windows.Storage.StorageFile])
  $outStream = Await-Op ($outFile.OpenAsync([Windows.Storage.FileAccessMode]::ReadWrite)) ([Windows.Storage.Streams.IRandomAccessStream])
  $outStream.Size = 0
  $encoder = Await-Op ([Windows.Graphics.Imaging.BitmapEncoder]::CreateAsync([Windows.Graphics.Imaging.BitmapEncoder]::JpegEncoderId, $outStream)) ([Windows.Graphics.Imaging.BitmapEncoder])
  $encoder.SetSoftwareBitmap($soft)
  Await-Action ($encoder.FlushAsync())
  $outStream.Dispose()
  $stream.Dispose()

  $outJpg = Join-Path $srcDir.FullName ($src.BaseName + '.jpg')
  Copy-Item -LiteralPath $tmpPath -Destination $outJpg -Force
  Remove-Item $tmpPath -Force -EA SilentlyContinue
  Write-Host "OK=$outJpg SIZE=$((Get-Item -LiteralPath $outJpg).Length)"
} catch {
  Write-Host "FAIL=$($_.Exception.Message)"
  if ($_.Exception.InnerException) { Write-Host "INNER=$($_.Exception.InnerException.Message)" }
  exit 2
}
