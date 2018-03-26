<?php

/**
 * csw
 * websocket 客户端实现
 * Class util_SocketClient
 */
class util_SocketClient
{
    private $_Socket = null;
    private $_buffer = '';

    /**csw | 每次从缓冲区读取的字节数
     * @var int
     */
    private $_perLength = 10;

    public function __construct($param,$isBlock=1)
    {
        $this->_connect($param['host'],$param['port'],$isBlock);
    }
    public function index()
    {
        sleep(5);
        for($i=1;$i<=20;$i++)
        {
            $pid = pcntl_fork();
            if($pid)
            {
            }
            else
            {
                $this->sendData($i.'#');
                exit();
            }
        }
    }

    public function sendData($data)
    {
        return fwrite($this->_Socket, $this->encode($data)) or die('Error:');
    }

    private function encode( $data ) {

        $data = is_array( $data ) || is_object( $data ) ? json_encode( $data ) : (string) $data;
        $len = strlen( $data );
        $mask=array();
        for ($j=0;$j<4;$j++) {
            $mask[]=mt_rand(1,255);
        }
        $head[0] = 129;
        if ( $len <= 125 ) {
            $head[1] = $len;
        } elseif ( $len <= 65535 ) {
            $split = str_split( sprintf('%016b', $len ), 8 );
            $head[1] = 126;
            $head[2] = bindec( $split[0] );
            $head[3] = bindec( $split[1] );
        } else {
            $split = str_split( sprintf('%064b', $len ), 8 );
            $head[1] = 127;
            for ( $i = 0; $i < 8; $i++ ) {
                $head[$i+2] = bindec( $split[$i] );
            }
            if ( $head[2] > 127 ) {
                return false;
            }
        }
        $head[1]+=128;
        $head=array_merge($head,$mask);
        foreach( $head as $k => $v ) {
            $head[$k] = chr( $v );
        }
        $mask_data='';
        for ($j=0;$j<$len;$j++) {
            $mask_data.=chr(ord($data[$j]) ^ $mask[$j % 4]);
        }
        return implode('', $head ) . $mask_data;
    }
    private function _connect($host, $port,$isBlock=1)
    {
        $key1 = $this->_generateRandomString(32);
        $key2 = $this->_generateRandomString(32);
        $key3 = $this->_generateRandomString(8, false, true);
        $header = "GET ws://".$host.":".$port."/ HTTP/1.1\r\n";
        $header.= "Host: ".$host.":".$port."\r\n";
        $header.= "Connection: Upgrade\r\n";
        $header.= "Pragma: no-cache\r\n";
        $header.= "Cache-Control: no-cache\r\n";
        $header.= "Upgrade: websocket\r\n";
        $header.= "Sec-WebSocket-Version: 13\r\n";
        $header.= "User-Agent: Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/39.0.2171.95 Safari/537.36\r\n";
        $header.= "Accept-Encoding: gzip, deflate, sdch\r\n";
        $header.= "Accept-Language: zh-CN,zh;q=0.8\r\n";
        $header.= "Sec-WebSocket-Key: " . $key1 . "\r\n";
        $header.= "Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits\r\n";
        $header.= "\r\n";
        $this->_Socket = fsockopen($host, $port, $errno, $errstr, 2);

        if(!$isBlock)
            stream_set_blocking  ($this->_Socket,0);

        fwrite($this->_Socket, $header) or die('Error: ' . $errno . ':' . $errstr);
        do{
            $resHand = fread($this->_Socket,2000);
            if($resHand)
                break;
        }while(true);

        /**
         * @todo: check response here. Currently not implemented cause "2 key handshake" is already deprecated.
         * See: http://en.wikipedia.org/wiki/WebSocket#WebSocket_Protocol_Handshake
         */
        return true;
    }
    public function receive()
    {
        $response = fread($this->_Socket,2000);
        $bitArr = $this->strToBit($response);
        $this->decode($bitArr);
    }
   //websocket解码函数
    private function decode($strBit)
    {
        if(empty($strBit))
            return ;

        $byte2 = $strBit[1];
        $lengthSign = substr($byte2,1);
        $lengthSign = bindec($lengthSign);
        if($lengthSign <=125)
        {
            $dataLength = $lengthSign;
            $packageLength = $dataLength + 2;
            $data = array_slice($strBit,2,$dataLength);
            $next = array_slice($strBit,2+$dataLength);
        }
        elseif($lengthSign == 126)
        {
            $byte3 = $strBit[2];
            $byte4 = $strBit[3];
            $dataLength = bindec($byte3.$byte4);
            $packageLength = $dataLength + 4;
            $data = array_slice($strBit,4,$dataLength);
            $next = array_slice($strBit,4+$dataLength);
        }
        elseif($lengthSign == 127)
        {
            $byte3 = $strBit[2];
            $byte4 = $strBit[3];
            $byte5 = $strBit[4];
            $byte6 = $strBit[5];
            $dataLength = bindec($byte3.$byte4.$byte5.$byte6);
            $packageLength = $dataLength + 8;
            $data  = array_slice($strBit,6,$dataLength);
            $next = array_slice($strBit,6+$dataLength);
        }
        $temp='';
        foreach ($data as $key=>$val)
        {
            $temp.= chr(bindec($val));
        }
        $this->_buffer[] = array(
                'length' => $dataLength,
                'data' => $temp,
        );

        if(!empty($next))
        {
            $this->decode($next);
        }
    }

    /**csw
     * 获取本次数据包的长度
     */
    private function getLength($strBit)
    {
        if(empty($strBit))
            return ;

        $byte2 = $strBit[1];
        $lengthSign = substr($byte2,1);
        $lengthSign = bindec($lengthSign);
        $dataLength = 0;
        $packageLength = 0;
        if($lengthSign <=125)
        {
            $dataLength = $lengthSign;
            $packageLength = $dataLength + 2;
        }
        elseif($lengthSign == 126)
        {
            $byte3 = $strBit[2];
            $byte4 = $strBit[3];
            $dataLength = bindec($byte3.$byte4);
            $packageLength = $dataLength + 4;
        }
        elseif($lengthSign == 127)
        {
            $byte3 = $strBit[2];
            $byte4 = $strBit[3];
            $byte5 = $strBit[4];
            $byte6 = $strBit[5];
            $dataLength = bindec($byte3.$byte4.$byte5.$byte6);
            $packageLength = $dataLength + 8;
        }

        $res = array(
            'dataLength' => $dataLength,
            'packageLength' => $packageLength,
        );

        return $res;
    }

    /**csw
     * 获取一个包里的数据
     */
    public function getOnePackage()
    {
        $response = fread($this->_Socket,$this->_perLength);
        if(!$response)
            return false;

        $bitArr = $this->strToBit($response);
        $responseLength = strlen($response);
        $length = $this->getLength($bitArr);
        $dataLength = $length['dataLength'];
        $packageLength = $length['packageLength'];
        $protocolLength = $packageLength - $dataLength;
        $realData = '';

        if($packageLength <= $responseLength)
        {
            $dataArr = array_slice($bitArr,$protocolLength,$dataLength);
            $realData = $this->getRealData($dataArr);
        }
        else
        {
            $dataArr = array_slice($bitArr,$protocolLength);
            $realData = $this->getRealData($dataArr);

            do
            {
                $needLength = $dataLength - strlen($realData);
                $response = fread($this->_Socket,$needLength);
                $responseLength = strlen($response);
                $realData.= $response;

            }while($needLength > $responseLength);
        }

        $res = array(
            'packageLength' => $packageLength,
            'dataLength' => $dataLength,
            'data'  => $realData,
        );
        return $res;
    }

    private function getRealData($dataArr)
    {
        $temp = '';
        foreach ($dataArr as $key=>$val)
        {
            $temp.=chr(bindec($val));
        }
        return $temp;
    }

    /**把字符串转化为二进制字符串
     * @param $str
     * @param bool $isArr
     * @return array|string
     */
    private function strToBit($str,$isArr=true)
    {
        $byteLength = strlen($str);       //字符串占用的字节数，不是占位符个个数
        $byteArr = array();
        for($i=0;$i<$byteLength;$i++)
        {
            $byteArr[] = str_pad(decbin(ord($str[$i])),8,'0',STR_PAD_LEFT);
        }

        if($isArr)
            return $byteArr;

        $byteStr = implode('',$byteArr);
        return $byteStr;
    }
    private function _generateRandomString($length = 10, $addSpaces = true, $addNumbers = true)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
        $useChars = array();
        // select some random chars:
        for($i = 0; $i < $length; $i++)
        {
            $useChars[] = $characters[mt_rand(0, strlen($characters)-1)];
        }
        // add spaces and numbers:
        if($addSpaces === true)
        {
            array_push($useChars, ' ', ' ', ' ', ' ', ' ', ' ');
        }
        if($addNumbers === true)
        {
            array_push($useChars, rand(0,9), rand(0,9), rand(0,9));
        }
        shuffle($useChars);
        $randomString = trim(implode('', $useChars));
        $randomString = substr($randomString, 0, $length);
        return $randomString;
    }
    private function _disconnect()
    {
        fclose($this->_Socket);
    }
    public function __destruct()
    {
        $this->_disconnect();
    }
     public function getData()
    {
        return $this->_buffer;
    }
}
