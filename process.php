<?php
require __DIR__ . '/vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Logger başlat
$log = new Logger('edifact');
$log->pushHandler(new StreamHandler($_ENV['LOG_FILE'], Logger::DEBUG));
$log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG)); // terminale de yaz

$log->info("işlem başlatıldı.");

// Klasör yolları
$inbox = $_ENV['INBOX_DIR'];
$outbox = $_ENV['OUTBOX_DIR'];
$archive = $_ENV['ARCHIVE_DIR'];
$error = $_ENV['ERROR_DIR'];

// XML dosyalarını oku
$log->info("Inbox klasörü kontrol ediliyor");
$files = glob($inbox . '/*.XML');
$files = glob($inbox . '/*.xml');

if (empty($files)) {
    $log->warning("Inbox klasöründe işlenecek XML dosyası bulunamadı.");
}

foreach ($files as $file) {
    $filename = basename($file);
    $log->info("Dosya bulundu. işleme alınacak dosyanın adı: $filename");

    try {
        $xml = simplexml_load_file($file);

        $log->info("XML başarıyla yüklendi. Yüklenen dosyanın adı: $filename");

        $ediString = convertXmlToEdifact($xml, $log);

        $ediFilename = basename($file, '.xml') . '.edi';
        $ediPath = $outbox . '/' . $ediFilename;

        file_put_contents($ediPath, $ediString);
        $log->info("EDIFACT dosyası outbox'a yazıldı. yazılan dosyanın adı: $ediFilename");

        // XML dosyasını archive klasörüne taşı
        $archivedPath = $archive . '/' . $filename;
        if (rename($file, $archivedPath)) {
            $log->info("XML dosyası archive klasörüne taşındı: $filename");
        } else {
            $log->warning("XML dosyası archive klasörüne taşınamadı: $filename");
        }

    } catch (Exception $e) {
        $log->error("Hata oluştu: " . $e->getMessage());

        // Hatalı dosyayı error klasörüne taşı
        $errorPath = $error . '/' . $filename;
        copy($file, $errorPath);
        unlink($file);
        $log->info("XML dosyası error klasörüne taşındı: $filename");
    }
}

$log->info("Tüm işlemler tamamlandı.");



//  SEGMENT SEGMENT EDIFACT ÜRETEN FONKSİYON
function convertXmlToEdifact(SimpleXMLElement $xml, Logger $log): string {
    $header = $xml->OrderHeader;
    $details = $xml->OrderDetails->Detail;
    $interchangeRef = rand(1000000, 9999999);
    $messageRef = $interchangeRef;

    $segments = [];
    $segments[] = "UNA:+.? '";
    $segments[] = "UNB+UNOC:2+{$header->SenderMailboxId}:14+{$header->ReceiverMailboxId}:14+" .
                  date('ymd') . ":" . date('Hi') . "+$interchangeRef++ORDERS'";
    $segments[] = "UNH+$messageRef+ORDERS:D:96A:UN:EAN008'";
    $segments[] = "BGM+220+{$header->OrderNumber}+9'";
    $segments[] = "DTM+137:{$header->OrderDate}:102'";
    $segments[] = "FTX+ZZZ+++{$header->FreeTextField}'";

    // NAD RFF, CUX, TDT
    $segments[] = "NAD+BY+{$header->GLNBuyer}::9++BRICOSTORE ROMANIA S.A.+Calea Giulesti, Nr. 1-3, Sector 6+BUCURESTI++060251+{$header->Currency}'";
    $segments[] = "NAD+DP+{$header->GLNShipTo}::9++DEPOZIT BANEASA \\ 1616+Soseaua Bucuresti-Ploiesti, nr. 42-+BUCURESTI++013696+{$header->Currency}'";
    $segments[] = "NAD+SU+{$header->GLNSupplier}::9++STANLEY BLACK & DECKER ROMANIA SRL +TURTURELELOR, PHOENICIA BUSSINESS C+BUCURESTI++30881+{$header->Currency}'";

    $segments[] = "RFF+API:47362'";
    $segments[] = "CUX+2:{$header->Currency}:9'";
    $segments[] = "TDT+12++:'";


    // Ürünler (LIN, PIA, IMD, QTY, DTM, PRI)
    $lineCount = 0;
    foreach ($details as $detail) {
        $lineCount++;
        $segments[] = "LIN+{$detail->DetailNumber}++{$detail->ItemEanBarcode}:EN'";
        $segments[] = "PIA+1+:IN::92'";
        $segments[] = "PIA+1+{$detail->ItemReceiverCode}:SA::91'";
        $segments[] = "IMD+F++:::{$detail->ItemDescription}'";
        $segments[] = "QTY+21:{$detail->ItemOrderedQuantity}:{$detail->ItemOrderedQuantityUom}'";
        $segments[] = "DTM+2:{$header->DeliveryDate}:102'";
        $segments[] = "PRI+AAA:{$detail->ItemNetPrice}::::{$detail->ItemOrderedQuantityUom}'";

    }

    // DiĞER sabitler
    $segments[] = "UNS+S'";
    $segments[] = "CNT+2:$lineCount'";
    $segments[] = "UNT+" . (count($segments) - 2) . "+$messageRef'";
    $segments[] = "UNZ+1+$interchangeRef'";

    $log->info("Dönüşüm işlemi yapıldı. " );
    return implode("\n", $segments);
}
