<?php

$db = new SQLite3('/var/www/html/apptest/lab.db');

$id = $_GET['id'] ?? '1';

$query = "SELECT id, username, email, role
          FROM users
          WHERE id = $id";

echo "<h2>Recherche utilisateur</h2>";
echo "<p>Requete : " . htmlspecialchars($query) . "</p>";

$result = $db->query($query);

echo "<table border='1'>";
echo "<tr>
        <th>ID</th>
        <th>Utilisateur</th>
        <th>Email</th>
        <th>Role</th>
      </tr>";

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['username']) . "</td>";
    echo "<td>" . htmlspecialchars($row['email']) . "</td>";
    echo "<td>" . htmlspecialchars($row['role']) . "</td>";
    echo "</tr>";
}

echo "</table>";

?>
