<?php
require_once 'db_connect.php';

echo "<h2>Kiểm tra bảng Users</h2>";

// Kiểm tra cấu trúc bảng
$result = $conn->query("DESCRIBE users");
echo "<h3>Cấu trúc bảng users:</h3>";
echo "<table border='1' style='border-collapse: collapse; padding: 10px;'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['Field']}</td>";
    echo "<td>{$row['Type']}</td>";
    echo "<td>{$row['Null']}</td>";
    echo "<td>{$row['Key']}</td>";
    echo "<td>{$row['Default']}</td>";
    echo "</tr>";
}
echo "</table>";

// Hiển thị dữ liệu users (ẩn password)
$result = $conn->query("SELECT id, username, role FROM users");
echo "<h3>Danh sách users:</h3>";
echo "<table border='1' style='border-collapse: collapse; padding: 10px;'>";
echo "<tr><th>ID</th><th>Username</th><th>Role</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['username']}</td>";
    echo "<td>" . (isset($row['role']) ? $row['role'] : '<em>NULL</em>') . "</td>";
    echo "</tr>";
}
echo "</table>";

$conn->close();
?>
