<?php
// Koneksi database
$conn = new mysqli("localhost", "root", "root", "db_saw", 3306);
if ($conn->connect_error) {
    die("Koneksi gagal: " . $conn->connect_error);
}

// Inisialisasi data awal jika tabel kosong
function init_data($conn) {
    // Cek jika tabel kriteria kosong
    $res = $conn->query("SELECT COUNT(*) as total FROM kriteria");
    if ($res->fetch_assoc()['total'] == 0) {
        // Tambahkan kriteria default
        $kriteria = [
            ['Kelengkapan Dokumen', 0.3, 'benefit'],
            ['Harga Jual', 0.1, 'cost'],
            ['Kondisi Eksterior', 0.1, 'benefit'],
            ['Kondisi Mesin', 0.4, 'benefit'],
            ['Kondisi Interior', 0.1, 'benefit']
        ];
        
        foreach ($kriteria as $k) {
            $conn->query("INSERT INTO kriteria (nama_kriteria, bobot, tipe) VALUES ('$k[0]', $k[1], '$k[2]')");
        }
    }
    
    // Cek jika tabel alternatif kosong
    $res = $conn->query("SELECT COUNT(*) as total FROM alternatif");
    if ($res->fetch_assoc()['total'] == 0) {
        // Tambahkan beberapa alternatif contoh
        $alternatif = [
            ['Toyota Avanza', [4, 150000000, 3, 4, 3]],
            ['Honda Brio', [5, 120000000, 4, 3, 4]],
            ['Suzuki Ertiga', [3, 130000000, 4, 4, 3]]
        ];
        
        foreach ($alternatif as $a) {
            $conn->query("INSERT INTO alternatif (nama_alternatif) VALUES ('$a[0]')");
            $alt_id = $conn->insert_id;
            foreach ($a[1] as $krit_index => $nilai) {
                // krit_id should be dynamic based on current kriteria
                // For initial data, we assume criteria IDs are 1, 2, 3, 4, 5
                $krit_id = $krit_index + 1; 
                $conn->query("INSERT INTO nilai_alternatif (id_alternatif, id_kriteria, nilai) VALUES ($alt_id, $krit_id, $nilai)");
            }
        }
    }
}

init_data($conn);

// Handle delete alternatif
if (isset($_GET['delete_alternatif_id'])) {
    $id_alternatif_to_delete = (int)$_GET['delete_alternatif_id'];
    
    $conn->begin_transaction();
    try {
        $delete_nilai_query = "DELETE FROM nilai_alternatif WHERE id_alternatif = $id_alternatif_to_delete";
        if (!$conn->query($delete_nilai_query)) {
            throw new Exception("Error deleting nilai_alternatif for alternative: " . $conn->error);
        }
        
        $delete_alternatif_query = "DELETE FROM alternatif WHERE id_alternatif = $id_alternatif_to_delete";
        if (!$conn->query($delete_alternatif_query)) {
            throw new Exception("Error deleting alternative: " . $conn->error);
        }
        
        $conn->commit();
        header("Location: ?page=alternatif");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error deleting alternative: " . $e->getMessage());
    }
}

// Handle delete kriteria
if (isset($_GET['delete_kriteria_id'])) {
    $id_kriteria_to_delete = (int)$_GET['delete_kriteria_id'];

    $conn->begin_transaction();
    try {
        // Hapus nilai_alternatif yang terkait dengan kriteria ini terlebih dahulu
        $delete_nilai_query = "DELETE FROM nilai_alternatif WHERE id_kriteria = $id_kriteria_to_delete";
        if (!$conn->query($delete_nilai_query)) {
            throw new Exception("Error deleting nilai_alternatif for criterion: " . $conn->error);
        }

        // Kemudian hapus kriteria itu sendiri
        $delete_kriteria_query = "DELETE FROM kriteria WHERE id_kriteria = $id_kriteria_to_delete";
        if (!$conn->query($delete_kriteria_query)) {
            throw new Exception("Error deleting criterion: " . $conn->error);
        }

        $conn->commit();
        header("Location: ?page=kriteria");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error deleting criterion: " . $e->getMessage());
    }
}

// Handle tambah kriteria
if (isset($_POST['tambah_kriteria'])) {
    $nama = $conn->real_escape_string($_POST['nama_kriteria']);
    $bobot = (float)$_POST['bobot'];
    $tipe = $conn->real_escape_string($_POST['tipe']);
    $conn->query("INSERT INTO kriteria (nama_kriteria, bobot, tipe) VALUES ('$nama', $bobot, '$tipe')");
}

// Handle tambah alternatif
if (isset($_POST['tambah_alternatif'])) {
    $nama = $conn->real_escape_string($_POST['nama_alternatif']);
    $conn->query("INSERT INTO alternatif (nama_alternatif) VALUES ('$nama')");
    $alt_id = $conn->insert_id;

    // Ambil daftar kriteria saat ini untuk memastikan semua kriteria terproses
    $current_kriteria = get_kriteria($conn); 

    foreach ($current_kriteria as $k) {
        $id_kriteria = $k['id_kriteria'];
        // Ambil nilai yang disubmit dari form, jika ada. Gunakan null jika tidak ada.
        $nilai_submitted = $_POST['nilai'][$id_kriteria] ?? null; 

        // Konversi ke float jika numeric, jika tidak, atur default 0
        // Ini memastikan bahwa setiap kriteria untuk alternatif baru memiliki nilai,
        // mencegah tampilan '-' jika tidak ada nilai yang diberikan.
        $nilai = is_numeric($nilai_submitted) ? (float)$nilai_submitted : 0; 
        
        $conn->query("INSERT INTO nilai_alternatif (id_alternatif, id_kriteria, nilai) VALUES ($alt_id, $id_kriteria, $nilai)");
    }
}

// Handle edit alternatif (penambahan logika baru untuk mengedit alternatif)
if (isset($_POST['edit_alternatif'])) {
    $id_alternatif_to_edit = (int)$_POST['id_alternatif_edit'];
    $new_nama_alternatif = $conn->real_escape_string($_POST['nama_alternatif_edit']);

    $conn->begin_transaction();
    try {
        // Update nama alternatif
        $update_alternatif_query = "UPDATE alternatif SET nama_alternatif = '$new_nama_alternatif' WHERE id_alternatif = $id_alternatif_to_edit";
        if (!$conn->query($update_alternatif_query)) {
            throw new Exception("Error updating alternative name: " . $conn->error);
        }

        // Update atau insert nilai_alternatif
        $current_kriteria = get_kriteria($conn);
        foreach ($current_kriteria as $k) {
            $id_kriteria = $k['id_kriteria'];
            $nilai_submitted = $_POST['nilai_edit'][$id_kriteria] ?? null;
            $nilai = is_numeric($nilai_submitted) ? (float)$nilai_submitted : 0;

            // Cek apakah nilai sudah ada untuk alternatif dan kriteria ini
            $check_sql = "SELECT COUNT(*) as total FROM nilai_alternatif WHERE id_alternatif = $id_alternatif_to_edit AND id_kriteria = $id_kriteria";
            $check_res = $conn->query($check_sql)->fetch_assoc();

            if ($check_res['total'] > 0) {
                // Jika sudah ada, lakukan UPDATE
                $update_nilai_query = "UPDATE nilai_alternatif SET nilai = $nilai WHERE id_alternatif = $id_alternatif_to_edit AND id_kriteria = $id_kriteria";
                if (!$conn->query($update_nilai_query)) {
                    throw new Exception("Error updating nilai_alternatif: " . $conn->error);
                }
            } else {
                // Jika belum ada, lakukan INSERT
                $insert_nilai_query = "INSERT INTO nilai_alternatif (id_alternatif, id_kriteria, nilai) VALUES ($id_alternatif_to_edit, $id_kriteria, $nilai)";
                if (!$conn->query($insert_nilai_query)) {
                    throw new Exception("Error inserting new nilai_alternatif: " . $conn->error);
                }
            }
        }
        
        $conn->commit();
        header("Location: ?page=alternatif");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error editing alternative: " . $e->getMessage());
    }
}


// Fungsi helper untuk mengambil data
function get_kriteria($conn) {
    $res = $conn->query("SELECT * FROM kriteria ORDER BY id_kriteria");
    $data = [];
    while ($row = $res->fetch_assoc()) $data[] = $row;
    return $data;
}

function get_alternatif($conn) {
    $res = $conn->query("SELECT * FROM alternatif ORDER BY id_alternatif");
    $data = [];
    while ($row = $res->fetch_assoc()) $data[] = $row;
    return $data;
}

function get_nilai($conn, $alt_id, $krit_id) {
    $res = $conn->query("SELECT nilai FROM nilai_alternatif WHERE id_alternatif=$alt_id AND id_kriteria=$krit_id");
    $row = $res->fetch_assoc();
    return $row ? $row['nilai'] : '-';
}

// Fungsi perhitungan SAW
function hitung_saw($conn) {
    $kriteria = get_kriteria($conn);
    $alternatif = get_alternatif($conn);
    $normal = [];
    $skor = [];
    $max_min = [];

    // Hitung max/min per kriteria
    foreach ($kriteria as $k) {
        $idk = $k['id_kriteria'];
        $max_res = $conn->query("SELECT MAX(nilai) as val FROM nilai_alternatif WHERE id_kriteria=$idk")->fetch_assoc();
        $max = $max_res ? $max_res['val'] : 0; 
        
        $min_res = $conn->query("SELECT MIN(nilai) as val FROM nilai_alternatif WHERE id_kriteria=$idk")->fetch_assoc();
        $min = $min_res ? $min_res['val'] : 0; 
        
        $max_min[$idk] = ['max' => $max, 'min' => $min];
    }

    // Normalisasi
    foreach ($alternatif as $a) {
        $ida = $a['id_alternatif'];
        foreach ($kriteria as $k) {
            $idk = $k['id_kriteria'];
            $val = get_nilai($conn, $ida, $idk);

            if (!is_numeric($val)) { 
                $normal[$ida][$idk] = 0; 
                continue;
            }

            if ($k['tipe'] == 'benefit') {
                if (is_numeric($max_min[$idk]['max']) && $max_min[$idk]['max'] != 0) {
                    $normal[$ida][$idk] = $val / $max_min[$idk]['max'];
                } else {
                    $normal[$ida][$idk] = 0;
                }
            } else {
                if (is_numeric($max_min[$idk]['min']) && $val != 0) {
                    $normal[$ida][$idk] = $max_min[$idk]['min'] / $val;
                } else {
                    $normal[$ida][$idk] = 0;
                }
            }
        }
    }

    // Hitung skor akhir
    foreach ($alternatif as $a) {
        $ida = $a['id_alternatif'];
        $skor[$ida] = 0;
        foreach ($kriteria as $k) {
            $idk = $k['id_kriteria'];
            $normal_val = $normal[$ida][$idk] ?? 0;
            $skor[$ida] += $normal_val * $k['bobot'];
        }
    }

    arsort($skor);
    return $skor;
}

// Fungsi untuk menampilkan matriks normalisasi
function tampil_normalisasi($conn) {
    $kriteria = get_kriteria($conn);
    $alternatif = get_alternatif($conn);
    $max_min = [];
    $normal = [];

    foreach ($kriteria as $k) {
        $idk = $k['id_kriteria'];
        $max_res = $conn->query("SELECT MAX(nilai) as val FROM nilai_alternatif WHERE id_kriteria=$idk")->fetch_assoc();
        $max = $max_res ? $max_res['val'] : 0;
        
        $min_res = $conn->query("SELECT MIN(nilai) as val FROM nilai_alternatif WHERE id_kriteria=$idk")->fetch_assoc();
        $min = $min_res ? $min_res['val'] : 0;
        $max_min[$idk] = ['max' => $max, 'min' => $min];
    }

    foreach ($alternatif as $a) {
        $ida = $a['id_alternatif'];
        foreach ($kriteria as $k) {
            $idk = $k['id_kriteria'];
            $val = get_nilai($conn, $ida, $idk);

            if (!is_numeric($val)) {
                $normal[$ida][$idk] = 0;
                continue;
            }

            if ($k['tipe'] == 'benefit') {
                $max = $max_min[$idk]['max'];
                $normal[$ida][$idk] = ($max && $max != 0) ? $val / $max : 0;
            } else {
                $min = $max_min[$idk]['min'];
                $normal[$ida][$idk] = ($val != 0) ? $min / $val : 0;
            }

        }
    }

    return $normal;
}

$page = $_GET['page'] ?? 'home';
$kriteria = get_kriteria($conn);
$alternatif = get_alternatif($conn);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <!-- 
        Penyesuaian Desain:
        - Menggunakan Bootstrap 5 untuk styling responsif, tabel, form, dan tombol.
        - Menggunakan Font Awesome untuk ikon.
        - Menggunakan DataTables.js untuk tabel interaktif.
        - Skema warna diinspirasi dari gambar "foto cyber.jpg" (biru gelap, ungu, pink muda, electric blue).
        - Font 'Inter' dari Google Fonts untuk keterbacaan yang lebih baik.
        - Penambahan header dan footer yang profesional dengan tema cyber.
        - Tata letak responsif menggunakan kelas-kelas Bootstrap.
        - Desain lebih modern dan minimalis dengan efek "glowing" halus.
        - Perbaikan: Logic "tambah alternatif" kini memastikan semua kriteria mendapatkan nilai (default 0 jika tidak disubmit).
        - Perubahan: Input "Harga Jual" di form "Tambah Alternatif Baru" kini menerima nilai besar (jutaan).
        - Perubahan: Penambahan kolom dan tombol "Hapus" di tabel "Data Alternatif", beserta logika PHP untuk penghapusan data terkait.
        - Perubahan: Penambahan kolom dan tombol "Hapus" di tabel "Data Kriteria", beserta logika PHP untuk penghapusan data terkait.
        - Penambahan: Fitur Edit Alternatif (tombol, modal, dan logika PHP UPDATE).
        - Penambahan: Konfirmasi penghapusan menggunakan Bootstrap Modal untuk keamanan data.
    -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SPK Metode SAW - Cyber Theme</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Google Fonts - Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <!-- DataTables CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css"/>
    
    <style>
        :root {
            --dark-blue: #0A0F2B;
            --medium-blue: #161B33;
            --light-blue: #202540;
            --accent-purple: #8C8CEB;
            --accent-electric-blue: #00FFFF;
            --accent-pink: #FF00FF;
            --text-light: #E0E0E0;
            --text-lighter: #F0F0F0;
            --border-color: #333C57;
            --shadow-glow: rgba(0, 255, 255, 0.2); /* Electric blue glow */
            --shadow-pink: rgba(255, 0, 255, 0.2); /* Pink glow */
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--dark-blue);
            color: var(--text-light);
            line-height: 1.6;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-x: hidden; /* Prevent horizontal scroll */
        }
        .navbar {
            background-color: var(--medium-blue);
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        .navbar-brand, .nav-link {
            color: var(--text-lighter) !important;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        .navbar-brand:hover, .nav-link:hover {
            color: var(--accent-electric-blue) !important;
        }
        .nav-link.active {
            color: var(--accent-electric-blue) !important;
            border-bottom: 2px solid var(--accent-electric-blue);
            padding-bottom: 5px;
        }

        .container-fluid {
            padding-top: 30px;
            padding-bottom: 30px;
            flex: 1;
        }
        .header-content {
            background: linear-gradient(45deg, var(--medium-blue), var(--light-blue));
            padding: 50px;
            border-radius: 15px;
            margin-bottom: 40px;
            text-align: center;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.4), 0 0 30px var(--shadow-glow);
            position: relative;
            overflow: hidden;
        }
        .header-content::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at center, rgba(0,255,255,0.1) 0%, transparent 70%);
            transform: rotate(45deg);
            animation: pulse-glow 5s infinite alternate;
        }
        @keyframes pulse-glow {
            from { opacity: 0.5; transform: scale(1); }
            to { opacity: 0.8; transform: scale(1.05); }
        }

        .header-content h1 {
            color: var(--accent-electric-blue);
            font-weight: 700;
            margin-bottom: 15px;
            font-size: 3rem;
            text-shadow: 0 0 10px var(--shadow-glow);
        }
        .header-content p {
            color: var(--text-lighter);
            font-size: 1.2rem;
            text-shadow: 0 0 5px rgba(0, 0, 0, 0.5);
        }

        .group-info {
            background-color: var(--light-blue);
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 40px;
            border: 1px solid var(--border-color);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        .group-info::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, var(--accent-electric-blue), var(--accent-pink));
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
        }
        .group-info h3 {
            color: var(--accent-electric-blue);
            margin-bottom: 15px;
            font-weight: 600;
        }
        .group-info p {
            margin-bottom: 8px;
            color: var(--text-light);
        }
        .group-info p:last-child {
            margin-bottom: 0;
        }

        .card {
            background-color: var(--medium-blue);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3), 0 0 10px var(--shadow-glow);
            margin-bottom: 30px;
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4), 0 0 20px var(--shadow-electric-blue);
        }
        .card-header {
            background: linear-gradient(90deg, var(--light-blue), var(--medium-blue));
            border-bottom: 1px solid var(--border-color);
            color: var(--accent-electric-blue);
            font-weight: 700;
            padding: 18px 25px;
            border-top-left-radius: 10px;
            border-top-right-radius: 10px;
        }
        .card-body {
            padding: 25px;
        }

        .table {
            color: var(--text-light);
            background-color: var(--light-blue);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden; /* Ensures rounded corners on inner table too */
        }
        .table th, .table td {
            border-color: var(--border-color);
            padding: 15px;
            vertical-align: middle;
        }
        .table thead th {
            background-color: var(--medium-blue);
            color: var(--accent-electric-blue);
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }
        .table tbody tr:nth-child(even) {
            background-color: var(--light-blue);
        }
        .table tbody tr:nth-child(odd) {
            background-color: #242945; /* Slightly different shade */
        }
        .table tbody tr:hover {
            background-color: #2F3452; /* More distinct hover effect */
            color: var(--accent-electric-blue);
        }

        .btn-primary {
            background-color: var(--accent-purple);
            border-color: var(--accent-purple);
            font-weight: 600;
            padding: 10px 25px;
            border-radius: 8px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        .btn-primary:hover {
            background-color: var(--accent-electric-blue);
            border-color: var(--accent-electric-blue);
            box-shadow: 0 0 20px var(--shadow-glow);
            transform: translateY(-2px);
        }
        .btn-primary:active {
            background-color: var(--accent-electric-blue) !important;
            border-color: var(--accent-electric-blue) !important;
            box-shadow: 0 0 10px var(--shadow-glow) !important;
            transform: translateY(0);
        }

        /* Delete button style */
        .btn-danger {
            background-color: #DC3545; /* Bootstrap default red */
            border-color: #DC3545;
            font-weight: 600;
            padding: 8px 15px; /* Slightly smaller for actions */
            border-radius: 6px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .btn-danger:hover {
            background-color: #C82333;
            border-color: #BD2130;
            box-shadow: 0 0 15px rgba(255, 0, 0, 0.3);
        }
        .btn-danger:active {
            background-color: #BD2130 !important;
            border-color: #B21F2D !important;
        }
        
        /* Edit button style (New) */
        .btn-info {
            background-color: #00BFFF; /* A shade of blue/cyan for edit */
            border-color: #00BFFF;
            font-weight: 600;
            padding: 8px 15px;
            border-radius: 6px;
            transition: background-color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
            color: #FFF;
        }
        .btn-info:hover {
            background-color: #009ACD;
            border-color: #009ACD;
            box-shadow: 0 0 15px rgba(0, 191, 255, 0.3);
            color: #FFF;
        }


        .form-control, .form-select {
            background-color: var(--light-blue);
            border: 1px solid var(--border-color);
            color: var(--text-lighter);
            padding: 12px 18px;
            border-radius: 8px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            background-color: var(--light-blue);
            border-color: var(--accent-electric-blue);
            box-shadow: 0 0 0 0.25rem rgba(0, 255, 255, 0.25), 0 0 10px var(--shadow-glow);
            color: var(--text-lighter);
        }
        .form-label {
            color: var(--accent-pink);
            margin-bottom: 8px;
            font-weight: 500;
        }
        small.form-text {
            color: var(--text-light);
            opacity: 0.8;
            font-size: 0.85em;
        }

        .list-unstyled li {
            padding: 8px 0;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.05);
            transition: color 0.2s ease;
        }
        .list-unstyled li:last-child {
            border-bottom: none;
        }
        .list-unstyled li:hover {
            color: var(--accent-electric-blue);
        }
        .list-unstyled li .fas {
            margin-right: 10px;
            color: var(--accent-pink); /* Icon color for lists */
        }
        
        /* Specific list icons */
        .list-unstyled li .text-primary { color: var(--accent-electric-blue) !important; }
        .list-unstyled li .text-info { color: var(--accent-pink) !important; }


        .alert-info {
            background-color: var(--light-blue);
            border-color: var(--border-color);
            color: var(--text-light);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2), 0 0 10px var(--shadow-glow);
            border-radius: 10px;
            padding: 20px;
        }
        .alert-link {
            color: var(--accent-electric-blue) !important;
            font-weight: 600;
            text-decoration: none;
            transition: color 0.3s ease;
        }
        .alert-link:hover {
            color: var(--accent-pink) !important;
            text-decoration: underline;
        }

        .footer {
            background-color: var(--medium-blue);
            color: var(--text-light);
            text-align: center;
            padding: 20px;
            margin-top: 40px;
            border-top: 1px solid var(--border-color);
            box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.2);
        }

        /* DataTables Customizations */
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_processing,
        .dataTables_wrapper .dataTables_paginate {
            color: var(--text-light);
            font-size: 0.95em;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            color: var(--text-light) !important;
            border: 1px solid var(--border-color);
            background-color: var(--light-blue);
            border-radius: 5px;
            margin: 0 4px;
            padding: 8px 12px;
            transition: background-color 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current,
        .dataTables_wrapper .dataTables_paginate .paginate_button.current:hover {
            background-color: var(--accent-electric-blue) !important;
            color: var(--dark-blue) !important;
            border-color: var(--accent-electric-blue) !important;
            box-shadow: 0 0 10px var(--shadow-glow);
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background-color: var(--medium-blue) !important;
            color: var(--accent-electric-blue) !important;
            border-color: var(--accent-electric-blue) !important;
        }
        .dataTables_length select,
        .dataTables_filter input {
            background-color: var(--light-blue);
            border-color: var(--border-color);
            color: var(--text-lighter);
            border-radius: 5px;
            padding: 5px 10px;
        }
        .dataTables_length select:focus,
        .dataTables_filter input:focus {
            border-color: var(--accent-electric-blue);
            box-shadow: 0 0 0 0.25rem rgba(0, 255, 255, 0.25);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="?page=home">
                <i class="fas fa-fingerprint me-2"></i>SPK SAW Cyber
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'home') ? 'active' : '' ?>" href="?page=home"><i class="fas fa-home me-1"></i>Beranda</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'kriteria') ? 'active' : '' ?>" href="?page=kriteria"><i class="fas fa-cogs me-1"></i>Data Kriteria</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'alternatif') ? 'active' : '' ?>" href="?page=alternatif"><i class="fas fa-project-diagram me-1"></i>Data Alternatif</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'perhitungan') ? 'active' : '' ?>" href="?page=perhitungan"><i class="fas fa-calculator me-1"></i>Perhitungan</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page == 'ranking') ? 'active' : '' ?>" href="?page=ranking"><i class="fas fa-trophy me-1"></i>Ranking</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <?php if ($page == 'home'): ?>
            <div class="header-content">
                <h1>Sistem Pendukung Keputusan Pemilihan Alternatif Terbaik</h1>
                <p class="lead">Menggunakan Metode <strong class="text-primary">Simple Additive Weighting (SAW)</strong> untuk pengambilan keputusan yang optimal.</p>
            </div>

            <div class="group-info">
                <h3><i class="fas fa-users-cog me-2"></i>Informasi Kelompok Pengembang:</h3>
                <p><i class="fas fa-user-shield me-2"></i>1. Alfath Maulana Kahfi (23670081)</p>
                <p><i class="fas fa-user-shield me-2"></i>2. Mochamad Zidan Faizal (23670089)</p>
                <p><i class="fas fa-user-shield me-2"></i>3. Iwan Aurelio Esyifa (23670131)</p>
                <p><i class="fas fa-user-shield me-2"></i>4. Amiril Fatkhul Rohman (23670069)</p>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-clipboard-list me-2"></i>Petunjuk Penggunaan Sistem
                </div>
                <div class="card-body">
                    <ol class="list-unstyled">
                        <li><i class="fas fa-arrow-right me-2"></i>Menu <strong>Data Kriteria</strong>: Kelola kriteria penilaian beserta bobot dan tipenya (Benefit/Cost).</li>
                        <li><i class="fas fa-arrow-right me-2"></i>Menu <strong>Data Alternatif</strong>: Kelola alternatif yang akan dinilai beserta nilai untuk setiap kriteria.</li>
                        <li><i class="fas fa-arrow-right me-2"></i>Menu <strong>Perhitungan</strong>: Melihat proses normalisasi matriks dan perhitungan skor SAW.</li>
                        <li><i class="fas fa-arrow-right me-2"></i>Menu <strong>Ranking</strong>: Melihat hasil akhir perankingan alternatif dari yang terbaik.</li>
                    </ol>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-cubes me-2"></i>Kriteria Saat Ini (<?= count($kriteria) ?>)
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <?php foreach ($kriteria as $k): ?>
                                    <li><i class="fas fa-network-wired me-2 text-primary"></i><?= htmlspecialchars($k['nama_kriteria']) ?> (Bobot: <?= $k['bobot'] ?>, Tipe: <?= $k['tipe'] ?>)</li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-sitemap me-2"></i>Alternatif Saat Ini (<?= count($alternatif) ?>)
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled">
                                <?php foreach ($alternatif as $a): ?>
                                    <li><i class="fas fa-server me-2 text-info"></i><?= htmlspecialchars($a['nama_alternatif']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'kriteria'): ?>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-cogs me-2"></i>Manajemen Data Kriteria
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="kriteriaTable" class="table table-dark table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Kriteria</th>
                                    <th>Bobot</th>
                                    <th>Tipe</th>
                                    <th>Aksi</th> <!-- Penambahan kolom Aksi untuk Kriteria -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kriteria as $index => $k): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($k['nama_kriteria']) ?></td>
                                        <td><?= $k['bobot'] ?></td>
                                        <td><?= $k['tipe'] ?></td>
                                        <td>
                                            <!-- Tombol Delete untuk Kriteria -->
                                            <button type="button" class="btn btn-danger btn-sm" title="Hapus Kriteria" 
                                                    data-bs-toggle="modal" data-bs-target="#confirmDeleteModal" 
                                                    data-id="<?= $k['id_kriteria'] ?>" data-type="kriteria" 
                                                    data-name="<?= htmlspecialchars($k['nama_kriteria']) ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <i class="fas fa-plus-square me-2"></i>Tambah Kriteria Baru
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label for="nama_kriteria" class="form-label"><i class="fas fa-tags me-1"></i>Nama Kriteria:</label>
                            <input type="text" class="form-control" id="nama_kriteria" name="nama_kriteria" required>
                        </div>
                        <div class="mb-3">
                            <label for="bobot" class="form-label"><i class="fas fa-weight-hanging me-1"></i>Bobot (0-1):</label>
                            <input type="number" class="form-control" id="bobot" name="bobot" step="0.01" min="0" max="1" required>
                        </div>
                        <div class="mb-3">
                            <label for="tipe" class="form-label"><i class="fas fa-filter me-1"></i>Tipe:</label>
                            <select class="form-select" id="tipe" name="tipe" required>
                                <option value="benefit">Benefit</option>
                                <option value="cost">Cost</option>
                            </select>
                        </div>
                        <button type="submit" name="tambah_kriteria" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Tambah Kriteria</button>
                    </form>
                </div>
            </div>

        <?php elseif ($page == 'alternatif'): ?>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-project-diagram me-2"></i>Manajemen Data Alternatif
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="alternatifTable" class="table table-dark table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>No</th>
                                    <th>Nama Alternatif</th>
                                    <?php foreach ($kriteria as $k): ?>
                                        <th><?= htmlspecialchars($k['nama_kriteria']) ?></th>
                                    <?php endforeach; // Perbaikan: Mengubah endphp menjadi endforeach ?>
                                    <th>Aksi</th> <!-- Kolom Aksi untuk Alternatif -->
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alternatif as $index => $a): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($a['nama_alternatif']) ?></td>
                                        <?php 
                                        $kriteria_values = [];
                                        foreach ($kriteria as $k): 
                                            $nilai_alternatif_saat_ini = get_nilai($conn, $a['id_alternatif'], $k['id_kriteria']);
                                            $kriteria_values[] = ['id_kriteria' => $k['id_kriteria'], 'nilai' => $nilai_alternatif_saat_ini];
                                        ?>
                                            <td><?= $nilai_alternatif_saat_ini ?></td>
                                        <?php endforeach; ?>
                                        <td>
                                            <!-- Tombol Edit untuk Alternatif -->
                                            <button type="button" class="btn btn-info btn-sm me-1" title="Edit Alternatif"
                                                    data-bs-toggle="modal" data-bs-target="#editAlternatifModal"
                                                    data-id="<?= $a['id_alternatif'] ?>"
                                                    data-name="<?= htmlspecialchars($a['nama_alternatif']) ?>"
                                                    data-kriteria-values='<?= json_encode($kriteria_values) ?>'
                                            >
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <!-- Tombol Delete untuk Alternatif -->
                                            <button type="button" class="btn btn-danger btn-sm" title="Hapus Alternatif" 
                                                    data-bs-toggle="modal" data-bs-target="#confirmDeleteModal" 
                                                    data-id="<?= $a['id_alternatif'] ?>" data-type="alternatif" 
                                                    data-name="<?= htmlspecialchars($a['nama_alternatif']) ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header">
                    <i class="fas fa-folder-plus me-2"></i>Tambah Alternatif Baru
                </div>
                <div class="card-body">
                    <form method="post">
                        <div class="mb-3">
                            <label for="nama_alternatif" class="form-label"><i class="fas fa-microchip me-1"></i>Nama Alternatif:</label>
                            <input type="text" class="form-control" id="nama_alternatif" name="nama_alternatif" required>
                        </div>
                        <h4 class="mt-4 mb-3 text-info"><i class="fas fa-chart-bar me-2"></i>Nilai Kriteria untuk Alternatif:</h4>
                        <?php foreach ($kriteria as $k): ?>
                            <div class="mb-3">
                                <label for="nilai_<?= $k['id_kriteria'] ?>" class="form-label"><i class="fas fa-arrow-right me-1"></i><?= htmlspecialchars($k['nama_kriteria']) ?>:</label>
                                <?php if ($k['nama_kriteria'] == 'Harga Jual'): ?>
                                    <!-- Input untuk Harga Jual: menerima nilai besar (jutaan), tanpa min/max 1-5 -->
                                    <input type="number" class="form-control" id="nilai_<?= $k['id_kriteria'] ?>" name="nilai[<?= $k['id_kriteria'] ?>]" step="100000" required>
                                    <small class="form-text">Masukkan nilai harga dalam Rupiah (e.g., 150000000 untuk 150 juta).</small>
                                <?php else: ?>
                                    <!-- Input untuk kriteria lain: tetap skala 1-5 -->
                                    <input type="number" class="form-control" id="nilai_<?= $k['id_kriteria'] ?>" name="nilai[<?= $k['id_kriteria'] ?>]" step="0.1" min="1" max="5" required>
                                    <small class="form-text">Skala Penilaian: 1 (Sangat Buruk) - 5 (Sangat Baik).</small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" name="tambah_alternatif" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Tambah Alternatif</button>
                    </form>
                </div>
            </div>

        <?php elseif ($page == 'perhitungan'): ?>
            <h2 class="text-center mb-5 text-primary"><i class="fas fa-calculator me-3"></i>Proses Perhitungan Metode SAW</h2>
            
            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-th me-2"></i>Matriks Keputusan Awal ($X_{ij}$)
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="matriksKeputusanTable" class="table table-dark table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>Alternatif</th>
                                    <?php foreach ($kriteria as $k): ?>
                                        <th><?= htmlspecialchars($k['nama_kriteria']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alternatif as $a): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['nama_alternatif']) ?></td>
                                        <?php foreach ($kriteria as $k): ?>
                                            <td><?= get_nilai($conn, $a['id_alternatif'], $k['id_kriteria']) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-compress-arrows-alt me-2"></i>Matriks Normalisasi ($R_{ij}$)
                </div>
                <div class="card-body">
                    <?php $normalisasi = tampil_normalisasi($conn); ?>
                    <div class="table-responsive">
                        <table id="normalisasiTable" class="table table-dark table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>Alternatif</th>
                                    <?php foreach ($kriteria as $k): ?>
                                        <th><?= htmlspecialchars($k['nama_kriteria']) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alternatif as $a): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($a['nama_alternatif']) ?></td>
                                        <?php foreach ($kriteria as $k): ?>
                                            <td><?= round($normalisasi[$a['id_alternatif']][$k['id_kriteria']], 4) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <i class="fas fa-balance-scale me-2"></i>Bobot Kriteria ($W_j$)
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="bobotKriteriaTable" class="table table-dark table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>Kriteria</th>
                                    <th>Bobot</th>
                                    <th>Tipe</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kriteria as $k): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($k['nama_kriteria']) ?></td>
                                        <td><?= $k['bobot'] ?></td>
                                        <td><?= $k['tipe'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="alert alert-info text-center" role="alert">
                <i class="fas fa-info-circle me-2"></i>Setelah melihat proses perhitungan, silakan lanjutkan ke menu <a href="?page=ranking" class="alert-link">Ranking</a> untuk melihat hasil akhir.
            </div>

        <?php elseif ($page == 'ranking'): ?>
            <h2 class="text-center mb-5 text-primary"><i class="fas fa-trophy me-3"></i>Hasil Perankingan Akhir SAW</h2>
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-award me-2"></i>Daftar Ranking Alternatif
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="rankingTable" class="table table-dark table-striped table-bordered w-100">
                            <thead>
                                <tr>
                                    <th>Ranking</th>
                                    <th>Alternatif</th>
                                    <th>Skor Akhir ($V_i$)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $hasil = hitung_saw($conn);
                                $ranking = 1;
                                foreach ($hasil as $id => $skor):
                                    $nama_alternatif_res = $conn->query("SELECT nama_alternatif FROM alternatif WHERE id_alternatif=$id")->fetch_assoc();
                                    $nama = $nama_alternatif_res ? $nama_alternatif_res['nama_alternatif'] : 'N/A';
                                ?>
                                    <tr>
                                        <td><?= $ranking++ ?></td>
                                        <td><?= htmlspecialchars($nama) ?></td>
                                        <td><?= round($skor, 4) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="alert alert-info text-center mt-4" role="alert">
                <i class="fas fa-lightbulb me-2"></i>Alternatif dengan skor tertinggi adalah rekomendasi terbaik berdasarkan perhitungan metode SAW.
            </div>
        <?php endif; ?>
    </div>

    <footer class="footer mt-auto py-3">
        <div class="container text-center">
            &copy; <?= date('Y') ?> SPK Metode SAW - Tema Cyber Security. Dibuat oleh Kelompok Cyber.
        </div>
    </footer>

    <!-- Modal Konfirmasi Hapus -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-labelledby="confirmDeleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background-color: var(--medium-blue); border: 1px solid var(--border-color); box-shadow: 0 0 20px var(--shadow-glow);">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title" id="confirmDeleteModalLabel" style="color: var(--accent-electric-blue);"><i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(1);"></button>
                </div>
                <div class="modal-body" style="color: var(--text-light);">
                    Apakah Anda yakin ingin menghapus <strong id="deleteItemType"></strong> '<strong id="deleteItemName"></strong>'? Tindakan ini tidak dapat dibatalkan.
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="background-color: var(--light-blue); border-color: var(--border-color); color: var(--text-light);">Batal</button>
                    <a id="confirmDeleteButton" class="btn btn-danger" href="#"><i class="fas fa-trash-alt me-1"></i>Hapus</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Edit Alternatif (New) -->
    <div class="modal fade" id="editAlternatifModal" tabindex="-1" aria-labelledby="editAlternatifModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="background-color: var(--medium-blue); border: 1px solid var(--border-color); box-shadow: 0 0 20px var(--shadow-glow);">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title" id="editAlternatifModalLabel" style="color: var(--accent-electric-blue);"><i class="fas fa-edit me-2"></i>Edit Data Alternatif</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(1);"></button>
                </div>
                <div class="modal-body" style="color: var(--text-light);">
                    <form id="editAlternatifForm" method="post">
                        <input type="hidden" id="edit_alternatif_id" name="id_alternatif_edit">
                        <div class="mb-3">
                            <label for="edit_nama_alternatif" class="form-label"><i class="fas fa-microchip me-1"></i>Nama Alternatif:</label>
                            <input type="text" class="form-control" id="edit_nama_alternatif" name="nama_alternatif_edit" required>
                        </div>
                        <h4 class="mt-4 mb-3 text-info"><i class="fas fa-chart-bar me-2"></i>Nilai Kriteria:</h4>
                        <div id="editKriteriaValues">
                            <!-- Input kriteria akan di-load di sini oleh JavaScript -->
                        </div>
                        <button type="submit" name="edit_alternatif" class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>Simpan Perubahan</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- DataTables JS -->
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
        $(document).ready(function() {
            // Initialize DataTables for all tables
            // Check if tables exist before initializing DataTables
            if ($('#kriteriaTable').length) {
                $('#kriteriaTable').DataTable();
            }
            if ($('#alternatifTable').length) {
                $('#alternatifTable').DataTable();
            }
            if ($('#matriksKeputusanTable').length) {
                $('#matriksKeputusanTable').DataTable();
            }
            if ($('#normalisasiTable').length) {
                $('#normalisasiTable').DataTable();
            }
            if ($('#bobotKriteriaTable').length) {
                $('#bobotKriteriaTable').DataTable();
            }
            if ($('#rankingTable').length) {
                $('#rankingTable').DataTable();
            }

            // JavaScript for Delete Confirmation Modal
            var confirmDeleteModal = document.getElementById('confirmDeleteModal');
            confirmDeleteModal.addEventListener('show.bs.modal', function (event) {
                // Button that triggered the modal
                var button = event.relatedTarget;
                // Extract info from data-bs-* attributes
                var itemId = button.getAttribute('data-id');
                var itemType = button.getAttribute('data-type');
                var itemName = button.getAttribute('data-name');

                // Update the modal's content.
                var modalBodyType = confirmDeleteModal.querySelector('#deleteItemType');
                var modalBodyName = confirmDeleteModal.querySelector('#deleteItemName');
                var confirmButton = confirmDeleteModal.querySelector('#confirmDeleteButton');

                if (itemType === 'kriteria') {
                    modalBodyType.textContent = 'kriteria';
                    confirmButton.href = '?page=kriteria&delete_kriteria_id=' + itemId;
                } else if (itemType === 'alternatif') {
                    modalBodyType.textContent = 'alternatif';
                    confirmButton.href = '?page=alternatif&delete_alternatif_id=' + itemId;
                }
                modalBodyName.textContent = itemName;
            });

            // JavaScript for Edit Alternatif Modal (New)
            var editAlternatifModal = document.getElementById('editAlternatifModal');
            editAlternatifModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget; // Button that triggered the modal
                
                var alternatifId = button.getAttribute('data-id');
                var alternatifName = button.getAttribute('data-name');
                var kriteriaValuesJson = button.getAttribute('data-kriteria-values');
                var kriteriaValues = JSON.parse(kriteriaValuesJson);

                // Populate the alternative name and ID
                editAlternatifModal.querySelector('#edit_alternatif_id').value = alternatifId;
                editAlternatifModal.querySelector('#edit_nama_alternatif').value = alternatifName;

                // Dynamically populate kriteria input fields
                var editKriteriaValuesDiv = editAlternatifModal.querySelector('#editKriteriaValues');
                editKriteriaValuesDiv.innerHTML = ''; // Clear previous content

                // Get kriteria data from PHP to ensure correct names and types
                // This assumes `kriteria` array is accessible globally or fetched via AJAX if truly dynamic
                // For this example, we'll assume it's available or we can build it from the HTML table header
                // A more robust solution for large apps would be to fetch this via a separate AJAX endpoint.
                // For now, let's embed kriteria info into a JS variable (simplified for direct PHP output)
                const allKriteria = <?= json_encode($kriteria); ?>;

                allKriteria.forEach(function(kriteriaItem) {
                    const kriteriaId = kriteriaItem.id_kriteria;
                    const kriteriaName = kriteriaItem.nama_kriteria;
                    const kriteriaType = kriteriaItem.tipe;

                    // Find the value for this specific kriteria for the current alternative
                    const foundValue = kriteriaValues.find(val => val.id_kriteria == kriteriaId);
                    const currentValue = foundValue ? foundValue.nilai : 0; // Default to 0 if not found

                    let inputHtml = `
                        <div class="mb-3">
                            <label for="edit_nilai_${kriteriaId}" class="form-label">
                                <i class="fas fa-arrow-right me-1"></i>${kriteriaName}:
                            </label>
                    `;
                    if (kriteriaName === 'Harga Jual') {
                        inputHtml += `
                            <input type="number" class="form-control" id="edit_nilai_${kriteriaId}" 
                                   name="nilai_edit[${kriteriaId}]" step="100000" 
                                   value="${currentValue}" required>
                            <small class="form-text">Masukkan nilai harga dalam Rupiah (e.g., 150000000).</small>
                        `;
                    } else {
                        inputHtml += `
                            <input type="number" class="form-control" id="edit_nilai_${kriteriaId}" 
                                   name="nilai_edit[${kriteriaId}]" step="0.1" min="1" max="5" 
                                   value="${currentValue}" required>
                            <small class="form-text">Skala Penilaian: 1 (Sangat Buruk) - 5 (Sangat Baik).</small>
                        `;
                    }
                    inputHtml += `</div>`;
                    editKriteriaValuesDiv.insertAdjacentHTML('beforeend', inputHtml);
                });
            });
        });
    </script>
</body>
</html>
