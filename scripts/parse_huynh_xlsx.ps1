$xmlPath = 'c:\xampp\htdocs\tntt\scripts\_xlsx_tmp\xl\worksheets\sheet1.xml'
[xml]$xml = Get-Content -Path $xmlPath -Encoding UTF8
$ns = New-Object System.Xml.XmlNamespaceManager($xml.NameTable)
$ns.AddNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main')
$rows = $xml.SelectNodes('//m:sheetData/m:row', $ns)

function Get-Cell([System.Xml.XmlElement]$row, [string]$col) {
    foreach ($c in $row.SelectNodes('m:c', $ns)) {
        $ref = $c.GetAttribute('r')
        if ($ref -match "^$col\d+$") {
            $t = $c.SelectSingleNode('m:is/m:t', $ns)
            if ($t) { return [System.Net.WebUtility]::HtmlDecode($t.InnerText) }
            $v = $c.SelectSingleNode('m:v', $ns)
            if ($v) { return $v.InnerText }
        }
    }
    return ''
}

$list = @()
foreach ($row in $rows) {
    $rnum = [int]$row.GetAttribute('r')
    if ($rnum -le 1) { continue }
    $ho = (Get-Cell $row 'C').Trim()
    if ($ho -eq '') { continue }
    $list += [ordered]@{
        ten_thanh = (Get-Cell $row 'B').Trim()
        ho_ten    = $ho
        ngay_sinh = (Get-Cell $row 'D').Trim()
        gioi_tinh = (Get-Cell $row 'E').Trim()
        lop       = (Get-Cell $row 'F').Trim()
        nganh     = (Get-Cell $row 'G').Trim()
        cap_bac   = (Get-Cell $row 'H').Trim()
        sdt       = (((Get-Cell $row 'I') -replace '\D', '')).Trim()
        email     = (Get-Cell $row 'J').Trim()
        vai_tro   = (Get-Cell $row 'K').Trim()
    }
}

$out = 'c:\xampp\htdocs\tntt\data\huynh_truong_list.json'
$json = $list | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText($out, $json, [System.Text.UTF8Encoding]::new($false))
Write-Host ("Parsed " + $list.Count + " people -> $out")
