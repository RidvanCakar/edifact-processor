 
<?php
require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$log = new Logger('edifact_logger');
$log->pushHandler(new StreamHandler(__DIR__ . '/' . $_ENV['LOG_FILE'], Logger::DEBUG));

// Klasörleri oku
$inbox = __DIR__ . '/' . $_ENV['INBOX_DIR'];
$archive = __DIR__ . '/' . $_ENV['ARCHIVE_DIR'];
$outbox = __DIR__ . '/' . $_ENV['OUTBOX_DIR'];
$error = __DIR__ . '/' . $_ENV['ERROR_DIR'];

$files = glob($inbox . '/*.txt'); 

foreach ($files as $file) {
    try {
        $filename = basename($file);
        $log->info("Dosya işleniyor: $filename");

        // Orijinal dosyayı archive'a kopyala
        copy($file, $archive . '/' . $filename);
        $log->info("Dosya archive klasörüne kopyalandı: $filename");

        // Dosya içeriğini oku
        $content = file_get_contents($file);

        // 🚧 Burada EDIFACT dönüşümünü yapacaksın
        $updatedContent = transformEdifact($content); // Bu fonksiyonu sen tanımlayacaksın

        // Dönüştürülmüş dosyayı outbox'a kaydet
        file_put_contents($outbox . '/' . $filename, $updatedContent);
        $log->info("Dosya outbox klasörüne yazıldı: $filename");

        // Son olarak inbox'tan sil
        unlink($file);
    } catch (Exception $e) {
        $log->error("Dosya işlenirken hata oluştu ($filename): " . $e->getMessage());

        // Hatalı dosyayı error klasörüne taşı
        rename($file, $error . '/' . $filename);
    }
}

//  Örnek dönüşüm fonksiyonu
function transformEdifact($content) {
    // Müşteri istediği biçime göre string değişiklikleri yapılabilir.
    // Örnek olarak: bazı segmentleri değiştir
    $content = str_replace('OLDVALUE', 'NEWVALUE', $content);

    return $content;
}
