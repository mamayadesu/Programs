<?php
class Main {
    
    private $args;
    private $config;
    private $logger;
    private $isConfigSavingNeeded = false;
    const VERSION = '1.2'; // Текущая версия
    
    public function __construct($args) {
        $this->args = $args;
        $this->loadLogger();
        $this->loadConfig();
        $this->start();
    }
    
    private function loadConfig() {
        if(! file_exists('NoUnmembersPosts.json')) {
            $file = fopen('NoUnmembersPosts.json', 'a');
            fwrite($file, json_encode(array('login' => 'not_stated', 'access_token' => 'not_stated')));
            fclose($file);
        }
        $this->config = json_decode(file_get_contents('NoUnmembersPosts.json'), true);
    }
    
    private function loadLogger() {
        $this->logger = fopen('NoUnmembersPosts.log', 'a');
    }
    
    private function log($text) {
        if(gettype($text) == 'string') {
            $log = date('[d.m.Y H:i:s]', time())." $text\n";
            print_ct($log);
            fwrite($this->logger, $log);
        }
    }
    
    private function saveConfig() {
        if(gettype($this->config) == 'array') {
            $file = fopen('NoUnmembersPosts.json', 'w');
            fwrite($file, json_encode($this->config));
            fclose($file);
        } else {
            $this->log("ПРОИЗОШЛА ОШИБКА ПРИ ЗАПИСИ КОНФИГА. this.config НЕ ЯВЛЯЕТСЯ МАССИВОМ!");
        }
    }
    
    private function auth() {
        if(! isset($this->config['login']) || $this->config['login'] == 'not_stated' || ! isset($this->config['access_token']) || $this->config['access_token'] == 'not_stated') {
            print_ct("Логин (пример: 79123456789): ");
            $login = readln();
            print_ct("Пароль: ");
            $password = readln();
            $auth_url = 'https://oauth.vk.com/token?username='.$login.'&password='.$password.'&grant_type=password&client_id=2274003&client_secret=hHbZxrka2uZ6jB1inYsH';
            try {
                $response = @file_get_contents($auth_url);
            } catch(Expection $e) {
                $response = false;
                print_ct("Произошла ошибка при выполнении запроса. Попробуйте ещё раз.\n\n");
                $this->auth();
            }
            if($response !== false && $response !== null) {
                $response = json_decode($response, true);
                if(! isset($response['error_description']) && isset($response['access_token'])) {
                    $this->config['login'] = $login;
                    $this->config['access_token'] = $response['access_token'];
                    $this->isConfigSavingNeeded = true;
                } else {
                    if(isset($response['error_description'])) {
                        print_ct("Ошибка авторизации. ".$response['error_description']."\n");
                    } else {
                        print_ct("Неизвестная ошибка.\n");
                    }
                    $this->auth();
                }
            } else {
                print_ct("Ошибка авторизации.\n");
                $this->auth();
            }
        }
    }
    
    private function settings() {
        if(! isset($this->config['interval'])) {
            print_ct("Интервал сканирования стены (в секундах): ");
            $interval = readln();
            $interval = (int) $interval;
            if($interval == 0) {
                print_ct("Неверное значение интервала.\n\n");
                $this->settings();
            } else {
                $this->config['interval'] = $interval;
                $this->isConfigSavingNeeded = true;
            }
        }
        if(! isset($this->config['page_id'])) {
            print_ct("ID страницы/группы (только число, без club, public и т.д): ");
            $page_id = readln();
            $page_id = (int) $page_id;
            if($page_id == 0) {
                print_ct("Неверное значение ID пользователя/группы.\n\n");
                $this->settings();
            } else {
                $this->config['page_id'] = $page_id;
                $this->isConfigSavingNeeded = true;
            }
        }
        if(! isset($this->config['count'])) {
            print_ct("Кол-во записей для проверки (максимум 100): ");
            $count = readln();
            $count = (int) $count;
            if($count == 0 || $count > 99) {
                print_ct("Неверное значение.\n\n");
                $this->settings();
            } else {
                $this->config['count'] = $count;
                $this->isConfigSavingNeeded = true;
            }
        }
    }
    
    private function start() {
        $this->auth();
        $this->settings();
        if($this->isConfigSavingNeeded === true) {
            print_ct("Сохранение настроек...\n");
            $this->saveConfig();
        }
        $this->log("NoUnmembersPosts v".Main::VERSION." запущен!");
        while(true) {
            try {
                $response = @file_get_contents('https://api.vk.com/method/wall.get?owner_id=-'.$this->config['page_id'].'&count='.$this->config['count'].'&access_token='.$this->config['access_token']);
            } catch(Expection $e) {
                $response = false;
                $this->log("Произошла ошибка при выполнении запроса. Попытка через ".$this->config['interval']." сек.");
            }
            if($response !== false) {
                $response = json_decode($response, true);
                if(! isset($response['response'])) {
                    $this->log("Ошибка. ".json_encode($response));
                    $response = array();
                } else {
                    $response = $response['response'];
                }
                foreach($response as $row) {
                    if(isset($row['signer_id']) && ! isset($row['created_by'])) {
                        $row['created_by'] = $row['signer_id'];
                    }
                    if(gettype($row) == 'array' && (((isset($row['created_by']) && isset($row['from_id']) && (isset($row['created_by']) && $row['from_id'] == $row['created_by']))) || (isset($row['from_id']) && ! isset($row['created_by'])))) {
                        if($row['from_id'] < 0) {
                            $row['from_id'] = $row['from_id'] * (-1);
                        }
                        try {
                            $response1 = @file_get_contents('https://api.vk.com/method/groups.isMember?group_id='.$this->config['page_id'].'&access_token='.$this->config['access_token'].'&user_id='.$row['from_id']);
                        } catch(Expection $e) {
                            $response1 = false;
                            $this->log("Произошла ошибка при выполнении запроса. Не удалось проверить, является ли пользователь ".$row['from_id']." (запись ".$row['id'].") участником группы. Пропущено.");
                        }
                        if($response1 !== false) {
                            $response1 = json_decode($response1, true);
                            if(! isset($response1['response'])) {
                                $this->log("Ошибка. ".json_encode($response1));
                                $response1 = 1;
                            } else {
                                $response1 = $response1['response'];
                            }
                            if($response1 == 0) {
                                try {
                                    $response2 = @file_get_contents('https://api.vk.com/method/wall.delete?owner_id=-'.$this->config['page_id'].'&access_token='.$this->config['access_token'].'&post_id='.$row['id']);
                                } catch(Expection $e) {
                                    $response2 = false;
                                    $this->log("Произошла ошибка при выполнении запроса. Не удалось удалить запись ".$row['id']." (пользователь ".$row['from_id']."). Пропущено.");
                                }
                                if($response2 !== false) {
                                    $this->log("Удалена запись ".$row['id']." (пользователь ".$row['from_id'].").");
                                }
                            }
                        }
                    }
                }
            }
            sleep($this->config['interval']);
        }
    }    
}

function readln() {

    $result = fgets(STDIN);
    
    $result = str_replace("\n", "", $result);
    
    $result = str_replace("\r", "", $result);
    
    return $result;
    
}

/**
 * Используется для вывода перекодированного текста (только для Windows). Чтобы работало, перекодируйте сам файл из UTF-8 в Windows-1251 (ANSI) и установите значение переменной $decode_to_cp866 с false на true
 *
 */
function print_ct($text) {
    $decode_to_cp866 = false;
    if($decode_to_cp866 === true) {
        echo iconv("CP1251", "CP866", $text);
    } else {
        echo $text;
    }
}

new Main($argv);
