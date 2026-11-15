<?php
// Улучшенная версия с конфигурационным файлом
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
ini_set('display_errors', '0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  header('Allow: POST');
  http_response_code(405);
  echo json_encode(['result' => 'error', 'status' => 'Метод не разрешен'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Конфигурация находится рядом с public_html, вне публичного корня сайта.
$configPath = getenv('NOVA_SWIM_MAIL_CONFIG') ?: dirname(__DIR__) . '/shared/mail-config.php';
if (!is_readable($configPath)) {
  http_response_code(503);
  echo json_encode(['result' => 'error', 'status' => 'Отправка почты ещё не настроена'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $config = require $configPath;
  if (!is_array($config) || empty($config['smtp']['host']) || empty($config['smtp']['username'])
      || empty($config['smtp']['password']) || empty($config['smtp']['port'])
      || !in_array($config['smtp']['secure'] ?? '', ['ssl', 'tls'], true)
      || !filter_var($config['from']['email'] ?? '', FILTER_VALIDATE_EMAIL)
      || empty($config['recipients']) || !is_array($config['recipients'])) {
    throw new RuntimeException('Некорректная конфигурация SMTP');
  }
} catch (Throwable $e) {
  error_log('Nova Swim: не удалось загрузить конфигурацию почты');
  http_response_code(503);
  echo json_encode(['result' => 'error', 'status' => 'Отправка почты ещё не настроена'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Проверка HTTPS (для продакшена)
if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
  // Для разработки закомментируйте следующие строки
  // header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
  // exit;
}

// Проверка origin (базовая защита от CSRF)
$allowed_origins = $config['allowed_origins'] ?? ['https://nova-swim.ru', 'https://www.nova-swim.ru'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($origin) && !in_array($origin, $allowed_origins)) {
  http_response_code(403);
  echo json_encode(["result" => "error", "status" => "Доступ запрещен"], JSON_UNESCAPED_UNICODE);
  exit;
}

// Подключение PHPMailer
require __DIR__ . '/phpmailer/PHPMailer.php';
require __DIR__ . '/phpmailer/SMTP.php';
require __DIR__ . '/phpmailer/Exception.php';

// Инициализация переменных
$body = '';
$result = "success";
$status = "Сообщение успешно отправлено";
$rfile = [];

// PHP заменяет пробелы в именах полей на подчёркивания.
foreach ($_POST as $value) {
  if (!is_string($value)) {
    http_response_code(400);
    echo json_encode(['result' => 'error', 'status' => 'Некорректные поля заявки'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
$phone = preg_replace('/\D+/', '', $_POST['Телефон'] ?? '');
if (strlen($phone) < 10 || strlen($phone) > 15 || empty($_POST['Согласие_на_обработку_персональных_данных'])) {
  http_response_code(400);
  echo json_encode(['result' => 'error', 'status' => 'Укажите телефон и согласие на обработку персональных данных'], JSON_UNESCAPED_UNICODE);
  exit;
}

// Проверка, есть ли файлы
$file = isset($_FILES['file']) ? $_FILES['file'] : null;

$c = true;
$title = "Новая заявка с сайта nova-swim.ru";

// Обработка POST-данных
foreach ($_POST as $key => $value) {
  $value = trim($value);
  if (!empty($value) && $key != "project_name" && $key != "admin_email" && $key != "form_subject") {
    $key = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $body .= ($c = !$c) ?
      "<tr><td style='padding: 10px; border: #e9e9e9 1px solid;'><b>$key</b></td><td style='padding: 10px; border: #e9e9e9 1px solid;'>$value</td></tr>" :
      "<tr style='background-color: #f8f8f8;'><td style='padding: 10px; border: #e9e9e9 1px solid;'><b>$key</b></td><td style='padding: 10px; border: #e9e9e9 1px solid;'>$value</td></tr>";
  }
}

// Добавляем информацию о времени и IP
$body .= "<tr style='background-color: #f0f8ff;'><td colspan='2' style='padding: 10px; border: #e9e9e9 1px solid; text-align: center; font-size: 12px; color: #666;'>";
$body .= "Время отправки: " . date('d.m.Y H:i:s') . " | IP: " . $_SERVER['REMOTE_ADDR'];
$body .= "</td></tr>";

$body = "<table style='width: 100%; border-collapse: collapse;'>$body</table>";

// Функция логирования
function logMailAttempt($data, $result, $error = null)
{
  // Не записываем данные заявки или SMTP-пароль в публичные файлы.
  error_log('Nova Swim mail: ' . $result);
}

// Отправка письма
$mail = new PHPMailer\PHPMailer\PHPMailer(true);

try {
  $mail->isSMTP();
  $mail->CharSet = 'UTF-8';
  $mail->SMTPAuth = true;

  // Настройки из конфига
  $mail->Host = $config['smtp']['host'];
  $mail->Username = $config['smtp']['username'];
  $mail->Password = $config['smtp']['password'];
  $mail->SMTPSecure = $config['smtp']['secure'];
  $mail->Port = $config['smtp']['port'];

  // Используем штатную проверку TLS-сертификата SMTP-сервера.
  $mail->Timeout = 15;
  $mail->getSMTPInstance()->Timelimit = 20;

  $mail->setFrom($config['from']['email'], $config['from']['name']);

  // Добавляем получателей из конфига
  foreach ($config['recipients'] as $email => $name) {
    $mail->addAddress($email, $name);
  }

  // Прикрепление файлов с улучшенной проверкой
  if ($file && !empty($file['name'][0])) {
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'txt'];
    $maxFileSize = 10 * 1024 * 1024; // 10MB

    for ($ct = 0; $ct < count($file['tmp_name']); $ct++) {
      if ($file['error'][$ct] === UPLOAD_ERR_OK) {
        // Проверка размера файла
        if ($file['size'][$ct] > $maxFileSize) {
          $rfile[] = "Файл {$file['name'][$ct]} слишком большой (макс. 10MB)";
          continue;
        }

        // Проверка типа файла
        $fileExtension = strtolower(pathinfo($file['name'][$ct], PATHINFO_EXTENSION));
        if (!in_array($fileExtension, $allowedTypes)) {
          $rfile[] = "Недопустимый тип файла {$file['name'][$ct]}";
          continue;
        }

        $uploadfile = tempnam(sys_get_temp_dir(), sha1($file['name'][$ct]));
        $filename = htmlspecialchars($file['name'][$ct], ENT_QUOTES, 'UTF-8');

        if (move_uploaded_file($file['tmp_name'][$ct], $uploadfile)) {
          $mail->addAttachment($uploadfile, $filename);
          $rfile[] = "Файл $filename прикреплён";
        } else {
          $rfile[] = "Ошибка при перемещении файла $filename";
          $result = "error";
        }
      } else {
        $errorMessages = [
          UPLOAD_ERR_INI_SIZE => 'Файл превышает максимальный размер',
          UPLOAD_ERR_FORM_SIZE => 'Файл превышает максимальный размер формы',
          UPLOAD_ERR_PARTIAL => 'Файл загружен частично',
          UPLOAD_ERR_NO_FILE => 'Файл не был загружен',
          UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
          UPLOAD_ERR_CANT_WRITE => 'Ошибка записи файла на диск',
          UPLOAD_ERR_EXTENSION => 'Загрузка остановлена расширением'
        ];

        $errorMsg = $errorMessages[$file['error'][$ct]] ?? "Неизвестная ошибка";
        $rfile[] = "Ошибка загрузки файла {$file['name'][$ct]}: $errorMsg";
        $result = "error";
      }
    }
  }

  // Настройка письма
  $mail->isHTML(true);
  $mail->Subject = $title;
  $mail->Body = $body;

  // Добавляем текстовую версию
  $mail->AltBody = strip_tags($body);

  // Отправка
  if (!$mail->send()) {
    $result = "error";
    $status = "Почта не отправлена. Попробуйте позже или свяжитесь с нами по телефону.";
    logMailAttempt($_POST, 'send_failed', $mail->ErrorInfo);
  } else {
    logMailAttempt($_POST, 'success');
  }
} catch (Throwable $e) {
  $result = "error";
  $status = "Почта не отправлена. Попробуйте позже или свяжитесь с нами по телефону.";
  logMailAttempt($_POST, 'exception', $e->getMessage());
}

// Гарантированно возвращаем JSON
if ($result !== 'success') {
  http_response_code(502);
}
echo json_encode([
  "result" => $result,
  "resultfile" => $rfile,
  "status" => $status
], JSON_UNESCAPED_UNICODE);
