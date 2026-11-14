<?php
/* PPT Bab 13: export data kunjungan ke JSON HL7 FHIR R4 (Bundle). Tempel ke https://validator.fhir.org untuk uji. */
require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/klinis.php';
wajib_akses('emr');
if (!role_is('dokter', 'rekammedis')) { http_response_code(403); exit('Akses ditolak.'); }
$id = (int) get('id');
if (!ambil_kunjungan($id)) { http_response_code(404); exit('Kunjungan tidak ditemukan.'); }
audit('Electronic Medical Record', 'Export FHIR', "Kunjungan #$id");
header('Content-Type: application/fhir+json; charset=utf-8');
if (get('unduh') === '1') header('Content-Disposition: attachment; filename="fhir-kunjungan-' . $id . '.json"');
echo json_encode(fhir_bundle($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
