<?php

try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=mfuko_schema', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->query('SELECT * FROM loans WHERE id = 20');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "Found loan 20\n";
    } else {
        echo "NO LOAN 20 found in mfuko_schema loans table\n";
    }

    $stmt = $pdo->query('SELECT * FROM loan_applications WHERE id = 20');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "Found loan application 20\n";
    } else {
        echo "NO LOAN APPLICATION 20 found\n";
    }
} catch (PDOException $e) {
    echo 'Connection failed: '.$e->getMessage()."\n";
}
