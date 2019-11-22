<?php
namespace FG;

require_once __DIR__.'/vendor/autoload.php';

use Pleo\BloomFilter\BloomFilter;

class FatGoose
{
    //配置数组
    private $config=[

        //创建布隆过滤器时使用的预估条目数(确保添加到布隆过滤器中的条目数小于这个值)
        'item_num'=>200000,
        //创建布隆过滤器时使用的误判几率
        'probability'=>0.0001,
        //无视布隆过滤器，开启时添加任务到任务表不会受到布隆过滤器的阻止
        'ignore_bloom_filter'=>false,

        //是否启用tor代理 这部分功能与 https://github.com/trimstray/multitor 项目配合使用
        //必须保证当前环境multitor已经正确安装配置并且已经创建了若干tor进程
        'use_tor'=>false,

        'tor_ports_range'=>[20000,20009],//tor进程端口范围


        //爬取失败重试次数
        'retry'=>1,

        //线程数
        'thread_num'=>15,

        //是否开启守护模式,周期性从抓取任务表中取任务进行抓取
        'daemon'=>false,
        //开启守护模式后间隔多少秒尝试进行一次爬取
        'daemon_interval'=>7200,

        //监视器时间间隔
        'monitor_interval'=>3600,

        //curl默认选项
        'curlOpt'=>
            [
                CURLOPT_RETURNTRANSFER => true,  //抓取结果作为字符串返回，不输出到页面
                CURLOPT_CONNECTTIMEOUT => 60,  //连接60秒超时
                CURLOPT_TIMEOUT => 120, //函数执行120秒超时
                CURLOPT_FOLLOWLOCATION => true,  //跟踪重定向

                //设置Accept-Encoding请求头。同时能对压缩的响应内容解码
                CURLOPT_ENCODING => 'gzip, deflate',
                //设置用户代理字符串
                //百度蜘蛛 Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)
                //谷歌机器人 Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36',
                CURLOPT_HEADEROPT => CURLHEADER_UNIFIED, //向目标服务器和代理服务器的请求都使用CURLOPT_HTTPHEADER定义的请求头
                //构建更加真实的请求头
                CURLOPT_HTTPHEADER=> [
                    'Connection: close',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                    'Upgrade-Insecure-Requests: 1',
                    'Accept-Language: en-US,en;q=0.9,de;q=0.8,ja;q=0.7,ru;q=0.6,zh-CN;q=0.5,zh;q=0.4',
                    'Cache-Control: no-cache',
                ],

                /*设置代理*/
                //CURLOPT_PROXYTYPE=>CURLPROXY_HTTP,//设置代理类型CURLPROXY_HTTP或CURLPROXY_SOCKS5
                //CURLOPT_PROXY=>'127.0.0.1:1080',//设置代理的ip地址和端口号

                /*抓取https页面必要*/
                //CURLOPT_SSL_VERIFYPEER=>true,
                //CURLOPT_CAINFO=>'c:/cacert.pem',
                //CURLOPT_SSL_VERIFYHOST=>2,
            ],
        //默认数据库配置
        'database'=>
            [
                //服务器地址
                'host'=>'',
                //用户名
                'username'=>'',
                //密码
                'password'=>'',
                //端口
                'port'=>3306,

            ]

    ];
    private $taskTableName;//抓取任务表名
    private $historyUrlsTableName;//抓取过的历史url表名
    private $monitorTableName;//监视任务表名
    private $pdo; //PDO对象
    private $bloomfilter;//布隆过滤器对象
    private $callbacksArr;//抓取回调函数数组
    /*回调函数数组的格式
       [
        ['0级抓取成功的回调函数','0级抓取成功但状态码有问题的回调函数','0级抓取失败的回调函数'],//0级任务回调函数
        ['1级抓取成功的回调函数','1级抓取成功但状态码有问题的回调函数','1级抓取失败的回调函数'],//1级任务回调函数
        ......
        ...... 以此类推可以无限级
        ]
     */
    private $monitorCallbacksArr;//监视回调函数数组，只需要成功的回调函数
    /*回调函数数组的格式
     [0级监视任务成功的回调函数,1级监视任务成功的回调函数,2级监视任务成功的回调函数......]
    */
    private $preparedPdoStatement;//数组，已经准备好的pdostatement对象
    private $torProxyPortsArr;//tor代理端口数组

    //保存一个回调函数，自动传入自定义信息数组的引用，既可以使用信息数组里面的信息，也可以自己注入一些信息到信息数组中
    //既然参数是引用，所以在定义这个回调函数的时候一定要注意函数的形式
    //要求返回一个curl额外选项的数组
    public $generateExtraCurlOpt;
    //构造函数
    public function __construct(string $databaseName,string $taskTableName,string $historyUrlsTableName,string $monitorTableName,array $configOpt=[])
    {
        $this->taskTableName=$taskTableName;
        $this->historyUrlsTableName=$historyUrlsTableName;
        $this->monitorTableName=$monitorTableName;


        //覆盖配置数组
        $this->config=self::customArrayMerge($this->config,$configOpt);

        //建立数据库的连接
        $dsn = "mysql:host={$this->config['database']['host']};port={$this->config['database']['port']}";
        $this->pdo=new \PDO($dsn,$this->config['database']['username'],$this->config['database']['password'],[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        //没有指定数据库和三个表，则创建
        $this->pdo->exec("CREATE DATABASE IF NOT EXISTS $databaseName  DEFAULT CHARACTER SET utf8");
        $this->pdo->exec("use {$databaseName}");
        $createTableSql=<<<"STR"
CREATE TABLE IF NOT EXISTS $taskTableName (
 `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
 `url` varchar(4096) NOT NULL,
 `level` tinyint(3) unsigned NOT NULL COMMENT '任务级别',
 `state` tinyint(3) unsigned NOT NULL COMMENT '0：未分配；1：已分配；6：成功抓取；7：成功抓取但状态码不符合预期；9:抓取失败',
 `httpcode` smallint(5) unsigned DEFAULT NULL COMMENT '响应状态码',
 `errorno` int(11) DEFAULT NULL COMMENT '错误号',
 `errorinfo` varchar(4096) DEFAULT NULL COMMENT '错误信息',
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
STR;
        $this->pdo->exec($createTableSql);
        //不存在抓取过的历史url表则创建
        $createTableSql=<<<"STR"
CREATE TABLE IF NOT EXISTS $historyUrlsTableName (
 `url` varchar(4096) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8
STR;
        $this->pdo->exec($createTableSql);
        //不存在监视任务表则创建
        $createTableSql=<<<"STR"
CREATE TABLE IF NOT EXISTS $monitorTableName (
 `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
 `url` varchar(4096) NOT NULL,
 `level` tinyint(3) unsigned NOT NULL COMMENT '监视器任务级别',
 `state` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '0：本轮监视未分配 1：本轮监视已分配',
 `httpcode` smallint(5) unsigned DEFAULT NULL COMMENT '响应状态码',
 `errorno` int(11) DEFAULT NULL COMMENT '错误号',
 `errorinfo` varchar(4096) DEFAULT NULL COMMENT '错误信息',
 `crawl_id` int(10) unsigned DEFAULT NULL COMMENT '对应抓取任务的id',
 PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8
STR;
        $this->pdo->exec($createTableSql);
        //创建好布隆过滤器对象
        $pdostatement=$this->pdo->query("SELECT url From {$this->historyUrlsTableName}");
        $urlsArr=$pdostatement->fetchAll(\PDO::FETCH_NUM);
        $urlsArr=array_column($urlsArr,0);

        $this->bloomfilter=BloomFilter::init($this->config['item_num'],$this->config['probability']);
        foreach($urlsArr as $url)
        {
            $this->bloomfilter->add($url);
        }


        //准备好一系列调用了prepare()的pdostatement对象
        $this->preparedPdoStatement=[
            //下面几个是抓取任务表用的
            "queryTask"=>$this->pdo->prepare("SELECT * FROM {$this->taskTableName} WHERE state=0 LIMIT 1 FOR UPDATE"),
            "updateTask"=>$this->pdo->prepare("UPDATE {$this->taskTableName} SET state=? WHERE id=?"),
            "updateTaskWithUnexpectedCode"=>$this->pdo->prepare("UPDATE {$this->taskTableName} SET state=?,httpcode=? WHERE id=?"),
            "updateTaskWithFail"=>$this->pdo->prepare("UPDATE {$this->taskTableName} SET state=?,errorno=?,errorinfo=? WHERE id=?"),
            "insertTask"=>$this->pdo->prepare("INSERT INTO {$this->taskTableName} (id,url,level,state) VALUES (null,?,?,0)"),
            //下面几个是历史url表用的
            "insertHistoryUrl"=>$this->pdo->prepare("INSERT INTO {$this->historyUrlsTableName} (url) VALUES (?)"),
            //下面几个是监视器任务表用的
            "queryMonitorTask"=>$this->pdo->prepare("SELECT * FROM {$this->monitorTableName} WHERE state=0 LIMIT 1 FOR UPDATE"),
            "updateMonitorTask"=>$this->pdo->prepare("UPDATE {$this->monitorTableName} SET state=? WHERE id=?"),
            "updateAllMonitorTask"=>$this->pdo->prepare("UPDATE {$this->monitorTableName} SET state=0"),
            "updateMonitorTaskOther"=>$this->pdo->prepare("UPDATE {$this->monitorTableName} SET httpcode=?,errorno=?,errorinfo=? WHERE id=?"),
        ];

    }

    //用于添加抓取任务 $taskUrl:[ [url,level],[url,level],...... ]，第二个参数为true可临时无视布隆过滤器
    public function addTask($taskArr,bool $ignoreBloomfilter=false)
    {
        //开始处理
        foreach($taskArr as $task)
        {
            //如果无视布隆过滤器开启或临时无视了布隆过滤器
            if($this->config['ignore_bloom_filter']||$ignoreBloomfilter)
            {
                //抓取任务表、历史url记录表、当前布隆过滤器三个地方都要追加
                $this->preparedPdoStatement['insertTask']->execute([$task[0],$task[1]]);
                $this->preparedPdoStatement['insertHistoryUrl']->execute([$task[0]]);
                $this->bloomfilter->add($task[0]);
            }
            else
            {
                //布隆过滤器里面要没有这个条目，有这个条目则什么也不做
                if(!($this->bloomfilter->exists($task[0])))
                {
                    //抓取任务表、历史url记录表、当前布隆过滤器三个地方都要追加
                    $this->preparedPdoStatement['insertTask']->execute([$task[0],$task[1]]);
                    $this->preparedPdoStatement['insertHistoryUrl']->execute([$task[0]]);
                    $this->bloomfilter->add($task[0]);
                }
            }
        }
    }

    private function allocateTask() //用于分配一个抓取任务
    {
        $this->pdo->beginTransaction();//开启事务
        $this->preparedPdoStatement['queryTask']->execute();
        if($row=$this->preparedPdoStatement['queryTask']->fetch(\PDO::FETCH_ASSOC))//取一行
        {
                $this->preparedPdoStatement['updateTask']->execute([1,$row['id']]);
                $this->pdo->commit();//事务提交
                return $row;
        }
        else //没有找到结果
        {
            $this->pdo->commit();//事务提交
            return null;
        }
    }

    private function allocateMonitorTask() //用于分配一个监视任务
    {
        $this->pdo->beginTransaction();//开启事务
        $this->preparedPdoStatement['queryMonitorTask']->execute();
        if($row=$this->preparedPdoStatement['queryMonitorTask']->fetch(\PDO::FETCH_ASSOC))//取一行
        {
            $this->preparedPdoStatement['updateMonitorTask']->execute([1,$row['id']]);
            $this->pdo->commit();//事务提交
            return $row;
        }
        else //没有找到结果
        {
            $this->pdo->commit();//事务提交
            return null;
        }
    }
    //设置curl资源的选项数组
    private function setCurlOptArr(&$curlResource,$optArr)
    {
        //如果使用tor代理，则需要设置好额外选项
        if($this->config['use_tor'])
        {
            //如果tor代理端口数组为空，则需要填充
            if(empty($this->torProxyPortsArr))
            {
                $tmpArr=[];
                //根据配置文件中的tor端口范围创建tor端口数组
                for($k=$this->config['tor_ports_range'][0];$k<=$this->config['tor_ports_range'][1];$k++)
                {
                    $tmpArr[]=$k;

                }
                $this->torProxyPortsArr=$tmpArr;
            }

            $optArr[CURLOPT_PROXYTYPE]=CURLPROXY_SOCKS5;
            $optArr[CURLOPT_PROXY]='127.0.0.1';
            $optArr[CURLOPT_PROXYPORT]=array_shift($this->torProxyPortsArr);
            curl_setopt_array($curlResource,$optArr);
        }
        else //不使用tor代理走这里
        {
            curl_setopt_array($curlResource,$optArr);
        }
    }

    //创建curl资源，设置抓取选项，并进行一些准备工作，传入任务数组，引用传入资源和自定义信息映射数组
    //返回curl资源
    private function createCurlRes($task,&$resCustomInfoMapArr)
    {
        //创建curl资源
        $curlRes = curl_init($task['url']);
        //用配置文件里面的选项数组初始化
        $optArr=$this->config['curlOpt'];

        /*填充信息数组，建立好当前curl资源和信息数组的映射关系*/
        $customInfoArr['task']=$task;//将任务数组添加到信息数组中
        //判断是否设置了回调函数专门为每个curl资源设置特定的选项
        if(!empty($this->generateExtraCurlOpt))
        {
            //调用回调函数，获取额外选项数组。传入信息数组的引用。
            $extraOptArr=($this->generateExtraCurlOpt)($customInfoArr);
            if(is_array($extraOptArr))
            {
                //此处必须要特殊处理，保证额外的抓取选项追加到数组最后面，如果没有CURLOPT_RETURNTRANSFER=>true或者
                //CURLOPT_FILE在CURLOPT_RETURNTRANSFER的前面，将无法正常工作！！！
                foreach($extraOptArr as $k=>$v)
                {
                    $optArr[$k]=$v;
                }
            }
        }
        $customInfoArr['opt']=$optArr;//将curl选项数组添加到信息数组中
        $this->setCurlOptArr($curlRes,$optArr);//设置好当前资源的抓取选项
        //建立curl资源和信息数组的映射关系
        $resCustomInfoMapArr[]=[$curlRes,$customInfoArr];
        return $curlRes;//返回curl资源
    }

    private function mCrawlWithMysql() //多线程异步爬虫(协同mysql数据库工作）
    {
        $mCurlRes=curl_multi_init(); //多线程curl的资源
        $resNum=0; //用于统计多线程curl资源中添加进去的curl资源数量
        $retryArr=[]; //重试数组，格式为 [[url,重试次数],[url,重试次数]...]

        //资源和自定义资源信息数组的映射数组，格式[[资源,信息数组],[资源,信息数组]...]，信息数组是个关联数组
        //目前信息数组里面已经有了'task'键保存任务数组，'opt'键保存抓取选项数组
        $resCustomInfoMapArr=[];

        //多少线程就分配多少任务
        for($i=0;$i<$this->config['thread_num'];$i++)
        {
            $task=$this->allocateTask();//分配一个任务,$task是个数组
            if($task) //要是个有效的任务，不能是null
            {

                //创建curl资源、设置抓取选项，填充映射数组等准备活动
                curl_multi_add_handle($mCurlRes, $this->createCurlRes($task,$resCustomInfoMapArr));
                $resNum++; //资源数量+1

            }
            else
            {
                break;//暂时没有任务可分配，跳出循环
            }
        }

        do {

            //所有线程的抓取都完成了，$running才是false
            curl_multi_exec($mCurlRes, $running);
            //等待任意curl的活动，获得等到超时，默认1秒，由curl_multi_select第二个参数决定
            curl_multi_select($mCurlRes);

            while($info = curl_multi_info_read($mCurlRes))//处理抓取完成的线程
            {
                $curlRes=$info['handle'];
                //获取信息数组，这个信息数组是自动填充的，注意和$resCustomInfoMapArr中的信息数组相区别
                $curlInfo=curl_getinfo($curlRes);

                //根据curl资源获取 索引位置、对应的信息数组、任务级别
                $index=array_search($curlRes,array_column($resCustomInfoMapArr,0));
                $customInfoArr=$resCustomInfoMapArr[$index][1];
                $level=$customInfoArr['task']['level'];

                //看看当前资源对应的抓取选项，如果有CURLOPT_FILE，需关闭打开的文件
                if(isset($customInfoArr['opt'][CURLOPT_FILE]))
                {
                    fclose($customInfoArr['opt'][CURLOPT_FILE]);
                }

                //判断抓取是否出错，空字符串代表无错
                //这里不能用curl_errno()取错误号，返回值永远为0，实在要取用$info['result']
                if(!curl_error($curlRes))
                {
                    //判断响应状态码是否符合预期
                    $responseCode=$curlInfo['http_code'];
                    if($responseCode==200)
                    {

                        //抓取到的内容
                        $content=curl_multi_getcontent($curlRes);
                        //几级任务调用几级抓取成功的回调函数
                        if(is_callable($this->callbacksArr[$level][0]))
                        {
                            //$newUrlsArr:[[url,url,......],false] false改为true可临时无视布隆过滤器
                            $newUrlsArr=$this->callbacksArr[$level][0]($curlInfo,$content,$customInfoArr,$this->pdo);
                            if(is_array($newUrlsArr))//如果是数组，则生成下一级别的任务
                            {
                                $newUrlsArrPrepared=[];
                                foreach($newUrlsArr[0] as $newUrl)
                                {
                                   //为了调用addTask()，构建合适的数组结构
                                    $newUrlsArrPrepared[]=[$newUrl,$level+1];
                                }
                                $this->addTask($newUrlsArrPrepared,$newUrlsArr[1]);
                            }
                        }
                        //更新任务状态
                        $this->preparedPdoStatement['updateTask']->execute([6,$customInfoArr['task']['id']]);
                    }
                    else
                    {

                        if(is_callable($this->callbacksArr[$level][1]))
                        {
                            $this->callbacksArr[$level][1]($curlInfo,$customInfoArr,$this->pdo);
                        }
                        //更新任务状态
                        $this->preparedPdoStatement['updateTaskWithUnexpectedCode']->execute([7,$responseCode,$customInfoArr['task']['id']]);
                    }
                }
                //出错了
                else
                {
                    if($this->config['retry']>=1) //设定重试次数至少要大于等于1后面才有意义
                    {
                        //在重试数组中去找这个url
                        $urlIndex=array_search($curlInfo['url'],array_column($retryArr,0));
                        if($urlIndex===false)//没找到
                        {
                            $retryArr[]=[$curlInfo['url'],1];//设定重试1次
                            //以此url再创建资源并加入多线程curl资源中
                            curl_multi_add_handle($mCurlRes, $this->createCurlRes($customInfoArr['task'],$resCustomInfoMapArr));
                            $resNum++; //资源数量+1

                        }
                        //找到了
                        else
                        {
                            //重试次数没有达到指定次数
                            if($retryArr[$urlIndex][1]<$this->config['retry'])
                            {
                                $retryArr[$urlIndex][1]++;//重试次数加1次
                                //以此url再创建资源并加入多线程curl资源中
                                curl_multi_add_handle($mCurlRes, $this->createCurlRes($customInfoArr['task'],$resCustomInfoMapArr));
                                $resNum++; //资源数量+1

                            }
                            else
                            {
                                //经过若干次重试尝试，确实抓取失败
                                //调用爬取失败的回调函数
                                //自动传入url、错误消息、错误号、自定义信息数组、pdo对象
                                if(is_callable($this->callbacksArr[$level][2]))
                                {
                                    $this->callbacksArr[$level][2]($curlInfo['url'],curl_error($curlRes),$info['result'],$customInfoArr,$this->pdo);
                                }
                                //更新任务状态
                                $errorno=$info['result'];
                                $errorinfo=curl_error($curlRes);
                                $this->preparedPdoStatement['updateTaskWithFail']->execute([9,$errorno,$errorinfo,$customInfoArr['task']['id']]);
                                unset($retryArr[$urlIndex]);//从重试数组中删除这一项，避免数组越来越大
                                $retryArr=array_values($retryArr);//重排索引
                            }
                        }
                    }
                    else
                    {
                        if(is_callable($this->callbacksArr[$level][2]))
                        {
                            $this->callbacksArr[$level][2]($curlInfo['url'],curl_error($curlRes),$info['result'],$customInfoArr,$this->pdo);
                        }
                        //更新任务状态
                        $errorno=$info['result'];
                        $errorinfo=curl_error($curlRes);
                        $this->preparedPdoStatement['updateTaskWithFail']->execute([9,$errorno,$errorinfo,$customInfoArr['task']['id']]);
                    }
                }
                //不管是否出错，都清理资源和自定义信息映射数组，避免越来越大
                unset($resCustomInfoMapArr[$index]);
                $resCustomInfoMapArr=array_values($resCustomInfoMapArr);//重排索引
                //不管抓取是否出错，都算这个资源的抓取完成了，释放资源，做一些收尾工作
                curl_multi_remove_handle($mCurlRes, $curlRes);
                $resNum--;
                curl_close($curlRes);

            }

            //补充多线程curl中的资源达到指定数量，差多少补多少
            for($i=0;$i<$this->config['thread_num']-$resNum;$i++)
            {
                $task=$this->allocateTask();//分配一个任务,$task是个数组
                if($task) //要是个有效的任务，不能是null
                {
                    curl_multi_add_handle($mCurlRes, $this->createCurlRes($task,$resCustomInfoMapArr));
                    $resNum++; //资源数量+1
                }
                else
                {
                    break;//只要分配到一个null就表示暂时没任务可分配，跳出循环
                }
            }

        } while ($running || $resNum);

        curl_multi_close($mCurlRes);//释放多线程curl的资源

    }

    public function setCallbacksArr(array $callbacksArr) //用于设置回调函数数组
    {
        $this->callbacksArr=$callbacksArr;
    }
    public function setMonitorCallbacksArr(array $monitorCallbacksArr)
    {
        $this->monitorCallbacksArr=$monitorCallbacksArr;
    }
    public function run() //爬虫跑起来
    {
        if($this->config['daemon'])//如果开启了守护模式
        {
            while(true)
            {
                $this->mCrawlWithMysql();//开始爬取
                sleep($this->config['daemon_interval']);
            }
        }
        else
        {
            $this->mCrawlWithMysql();//开始爬取
        }
    }
    public function monitor()//监视器监视起来，监视器向抓取任务表追加或更新任务
    {
        while(true)
        {
            $this->monitorMCrawlWithMysql();
            //完成一轮监视后控制台输出一些信息
            date_default_timezone_set("Asia/Shanghai");
            echo "本轮监视已完成：".date("Y-m-d H:i:s");
            sleep($this->config['monitor_interval']); //暂停若干秒
        }

    }
    private function monitorMCrawlWithMysql()
    {
        $mCurlRes=curl_multi_init(); //多线程curl的资源
        $resNum=0; //用于统计多线程curl资源中添加进去的curl资源数量
        $retryArr=[]; //重试数组，格式为 [[url,重试次数],[url,重试次数]...]

        //资源和自定义资源信息数组的映射数组，格式[[资源,信息数组],[资源,信息数组]...]，信息数组是个关联数组
        $resCustomInfoMapArr=[];

        //多少线程就分配多少任务
        for($i=0;$i<$this->config['thread_num'];$i++)
        {
            $task=$this->allocateMonitorTask();//分配一个监视任务
            if($task) //要是个有效的任务，不能是null
            {
                //创建curl资源、设置抓取选项，并做一些准备活动
                curl_multi_add_handle($mCurlRes, $this->createCurlRes($task,$resCustomInfoMapArr));
                $resNum++; //资源数量+1

            }
            else
            {
                break;//暂时没有任务可分配，跳出循环
            }
        }
        do {
            //所有线程的抓取都完成了，$running才是false
            curl_multi_exec($mCurlRes, $running);
            //等待任意curl的活动，获得等到超时，默认1秒，由curl_multi_select第二个参数决定
            curl_multi_select($mCurlRes);
            while($info = curl_multi_info_read($mCurlRes))//处理抓取完成的线程
            {
                $curlRes=$info['handle'];
                //获取信息数组
                $curlInfo=curl_getinfo($curlRes);

                $index=array_search($curlRes,array_column($resCustomInfoMapArr,0));
                $customInfoArr=$resCustomInfoMapArr[$index][1];
                $level=$customInfoArr['task']['level'];//找到监视任务级别
                //判断抓取是否出错，空字符串代表无错
                //这里不能用curl_errno()取错误号，返回值永远为0，实在要取用$info['result']
                if(!curl_error($curlRes))
                {
                    //判断响应状态码是否符合预期
                    $responseCode=$curlInfo['http_code'];
                    if($responseCode==200)
                    {
                        //抓取到的内容
                        $content=curl_multi_getcontent($curlRes);
                        //几级任务调用几级回调函数
                        if(is_callable($this->monitorCallbacksArr[$level]))
                        {
                            $newUrlsArr=$this->monitorCallbacksArr[$level]($curlInfo,$content,$customInfoArr,$this->pdo,$this->bloomfilter);
                            if(is_array($newUrlsArr))//如果是数组，则向抓取任务表中添加新任务
                            {
                                $newUrlsArrPrepared=[];
                                foreach($newUrlsArr[0] as $newUrl)
                                {
                                    //为了调用addTask()，构建合适的数组结构
                                    $newUrlsArrPrepared[]=[$newUrl,$level+1];
                                }
                                $this->addTask($newUrlsArrPrepared,$newUrlsArr[1]);
                            }
                            elseif($newUrlsArr===true) //true则表示更新抓取任务表中的对应任务状态为0（未分配）
                            {
                                $this->preparedPdoStatement['updateTask']->execute([0,$customInfoArr['task']['crawl_id']]);
                            }
                        }
                        //更新监视任务状态
                        $this->preparedPdoStatement['updateMonitorTaskOther']->execute([null,null,null,$customInfoArr['task']['id']]);
                    }
                    else
                    {
                        //更新任务状态
                        $this->preparedPdoStatement['updateMonitorTaskOther']->execute([$responseCode,null,null,$customInfoArr['task']['id']]);
                    }
                }
                //出错了
                else
                {
                    if($this->config['retry']>=1) //设定重试次数至少要大于等于1后面才有意义
                    {
                        //在重试数组中去找这个url
                        $urlIndex=array_search($curlInfo['url'],array_column($retryArr,0));
                        if($urlIndex===false)//没找到
                        {
                            $retryArr[]=[$curlInfo['url'],1];//设定重试1次
                            curl_multi_add_handle($mCurlRes, $this->createCurlRes($customInfoArr['task'],$resCustomInfoMapArr));
                            $resNum++; //资源数量+1

                        }
                        //找到了
                        else
                        {
                            //重试次数没有达到指定次数
                            if($retryArr[$urlIndex][1]<$this->config['retry'])
                            {
                                $retryArr[$urlIndex][1]++;//重试次数加1次
                                curl_multi_add_handle($mCurlRes, $this->createCurlRes($customInfoArr['task'],$resCustomInfoMapArr));
                                $resNum++; //资源数量+1

                            }
                            else
                            {
                                //更新任务状态
                                $errorno=$info['result'];
                                $errorinfo=curl_error($curlRes);
                                $this->preparedPdoStatement['updateMonitorTaskOther']->execute([null,$errorno,$errorinfo,$customInfoArr['task']['id']]);
                                unset($retryArr[$urlIndex]);//从重试数组中删除这一项，避免数组越来越大
                                $retryArr=array_values($retryArr);//重排索引
                            }
                        }
                    }
                    else
                    {
                        //更新任务状态
                        $errorno=$info['result'];
                        $errorinfo=curl_error($curlRes);
                        $this->preparedPdoStatement['updateMonitorTaskOther']->execute([null,$errorno,$errorinfo,$customInfoArr['task']['id']]);
                    }
                }
                //不管是否出错，都清理资源任务对应关系数组，避免越来越大
                unset($resCustomInfoMapArr[$index]);
                $resCustomInfoMapArr=array_values($resCustomInfoMapArr);//重排索引
                //不管抓取是否出错，都算这个资源的抓取完成了，释放资源，做一些收尾工作
                curl_multi_remove_handle($mCurlRes, $curlRes);
                $resNum--;
                curl_close($curlRes);
            }

            //补充多线程curl中的资源达到指定数量，差多少补多少
            for($i=0;$i<$this->config['thread_num']-$resNum;$i++)
            {
                $task=$this->allocateMonitorTask();//分配一个监视任务
                if($task) //要是个有效的任务，不能是null
                {
                    curl_multi_add_handle($mCurlRes, $this->createCurlRes($task,$resCustomInfoMapArr));
                    $resNum++; //资源数量+1

                }
                else
                {
                    break;//只要分配到一个null就表示暂时没任务可分配，跳出循环
                }
            }

        } while ($running || $resNum);

        curl_multi_close($mCurlRes);//释放多线程curl的资源
        //一轮监视完成以后，要将监视状态全部设置为未分配(0)，以便进行下一轮监视
        $this->preparedPdoStatement['updateAllMonitorTask']->execute();
    }
    /**
     * 多线程异步爬虫
     * @param array|string $url 待抓取的目标页面
     * @param callable|null $onSuccess 抓取成功(状态码200)的回调函数
     * p1:curl信息数组 p2:抓取到的内容
     * @param callable|null $onSuccessWithUnexpectedCode 抓取成功但状态码不符合预期的回调函数
     * p1:curl信息数组
     * @param callable|null $onFail  抓取失败的回调函数
     * p1:目标url p2:错误消息 p3:错误码
     * @param int $threadNum 线程数
     * @param array $opt 抓取选项
     * @param int $retryNum 重试次数
     */

    public static function mCrawl(
        $url,
        callable $onSuccess=null,
        callable $onSuccessWithUnexpectedCode=null,
        callable $onFail=null,
        int $threadNum=10,//默认10线程
        int $retryNum=1, //重试次数
        array $opt=[
            CURLOPT_RETURNTRANSFER => true,  //抓取结果作为字符串返回，不输出到页面
            CURLOPT_CONNECTTIMEOUT => 60,  //连接60秒超时
            CURLOPT_TIMEOUT => 120, //函数执行120秒超时
            CURLOPT_FOLLOWLOCATION => true,  //跟踪重定向

            //设置Accept-Encoding请求头。同时能对压缩的响应内容解码
            CURLOPT_ENCODING => 'gzip, deflate',
            //设置用户代理字符串
            //百度蜘蛛 Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)
            //谷歌机器人 Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/73.0.3683.103 Safari/537.36',
            CURLOPT_HEADEROPT => CURLHEADER_UNIFIED, //向目标服务器和代理服务器的请求都使用CURLOPT_HTTPHEADER定义的请求头
            //构建更加真实的请求头
            CURLOPT_HTTPHEADER=> [
                'Connection: close',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8',
                'Upgrade-Insecure-Requests: 1',
                'Accept-Language: en-US,en;q=0.9,de;q=0.8,ja;q=0.7,ru;q=0.6,zh-CN;q=0.5,zh;q=0.4',
                'Cache-Control: no-cache',
            ],

            /*设置代理*/
            //CURLOPT_PROXYTYPE=>CURLPROXY_HTTP,//设置代理类型CURLPROXY_HTTP或CURLPROXY_SOCKS5
            //CURLOPT_PROXY=>'127.0.0.1:1080',//设置代理的ip地址和端口号

            /*抓取https页面必要*/
            //CURLOPT_SSL_VERIFYPEER=>true,
            //CURLOPT_CAINFO=>'c:/cacert.pem',
            //CURLOPT_SSL_VERIFYHOST=>2,
        ]
 )
    {

        $mCurlRes=curl_multi_init(); //多线程curl的资源
        $resNum=0; //用于统计多线程curl资源中添加进去的curl资源数量
        $retryArr=[]; //重试数组，格式为 [[url,重试次数],[url,重试次数]...]
        $targetUrlArr=[]; //待爬取的目标页面
        if(is_string($url))
        {
            $targetUrlArr[0]=$url;
        }
        elseif(is_array($url))
        {
            $targetUrlArr=$url;
        }

        for($i=0;$i<$threadNum;$i++)
        {
            $aTargetUrl=array_shift($targetUrlArr);
            if($aTargetUrl) //要是个有效的url，不能是null
            {
                $curlRes = curl_init($aTargetUrl);
                curl_setopt_array($curlRes,$opt);
                curl_multi_add_handle($mCurlRes, $curlRes);
                $resNum++; //资源数量+1

            }
            else
            {
                break;//$targetUrlArr里面没东西了跳出循环
            }
        }
        do {
            //所有线程的抓取都完成了，$running才是false
            curl_multi_exec($mCurlRes, $running);
            //等待任意curl的活动，获得等到超时，默认1秒，由curl_multi_select第二个参数决定
            curl_multi_select($mCurlRes);
            while($info = curl_multi_info_read($mCurlRes))//处理抓取完成的线程
            {
                $curlRes=$info['handle'];
                //获取信息数组
                $curlInfo=curl_getinfo($curlRes);
                //判断抓取是否出错，空字符串代表无错
                if(!curl_error($curlRes))
                {
                    //判断响应状态码是否符合预期
                    $responseCode=$curlInfo['http_code'];
                    if($responseCode==200)
                    {
                        //抓取到的内容
                        $content=curl_multi_getcontent($curlRes);
                        //调用爬取成功的回调函数
                        //自动传入参数信息数组、抓取内容
                        if(is_callable($onSuccess))
                        {
                            $onSuccess($curlInfo,$content);
                        }

                    }
                    else
                    {
                        //调用爬取成功但是响应状态码不符合预期的回调函数
                        //自动传入参数信息数组
                        if(is_callable($onSuccessWithUnexpectedCode))
                        {
                            $onSuccessWithUnexpectedCode($curlInfo);
                        }
                    }
                }
                //出错了
                else
                {
                    if($retryNum>=1) //设定重试次数至少要大于等于1后面才有意义
                    {
                        //在重试数组中去找这个url
                        $index=array_search($curlInfo['url'],array_column($retryArr,0));
                        if($index===false)//没找到
                        {
                            $retryArr[]=[$curlInfo['url'],1];//设定重试1次
                            //以此url再创建资源并加入多线程curl资源中
                            $moreCurlRes = curl_init($curlInfo['url']);
                            curl_setopt_array($moreCurlRes,$opt);
                            curl_multi_add_handle($mCurlRes, $moreCurlRes);
                            $resNum++; //资源数量+1
                        }
                        //找到了
                        else
                        {
                            //重试次数没有达到指定次数
                            if($retryArr[$index][1]<$retryNum)
                            {
                                $retryArr[$index][1]++;//重试次数加1次
                                //以此url再创建资源并加入多线程curl资源中
                                $moreCurlRes = curl_init($curlInfo['url']);
                                curl_setopt_array($moreCurlRes,$opt);
                                curl_multi_add_handle($mCurlRes, $moreCurlRes);
                                $resNum++; //资源数量+1
                            }
                            else
                            {
                                //经过若干次重试尝试，确实抓取失败
                                //调用爬取失败的回调函数
                                //自动传入url、错误消息、错误号
                                if(is_callable($onFail))
                                {
                                    $onFail($curlInfo['url'],curl_error($curlRes),curl_errno($curlRes));
                                }
                                unset($retryArr[$index]);//从重试数组中删除这一项，避免数组越来越大
                                $retryArr=array_values($retryArr);//重排索引
                            }
                        }
                    }
                    else
                    {
                        if(is_callable($onFail))
                        { $onFail($curlInfo['url'],curl_error($curlRes),curl_errno($curlRes)); }
                    }
                }
                //不管抓取是否出错，都算这个资源的抓取完成了，释放资源，做一些收尾工作
                curl_multi_remove_handle($mCurlRes, $curlRes);
                $resNum--;
                curl_close($curlRes);
            }

            for($i=0;$i<$threadNum-$resNum;$i++)
            {
                $aTargetUrl=array_shift($targetUrlArr);
                if($aTargetUrl) //要是个有效的url，不能是null
                {
                    $curlRes = curl_init($aTargetUrl);
                    curl_setopt_array($curlRes,$opt);
                    curl_multi_add_handle($mCurlRes, $curlRes);
                    $resNum++; //资源数量+1

                }
                else
                {
                    break;//$targetUrlArr里面没东西了跳出循环
                }
            }
        } while ($running || $resNum);

        curl_multi_close($mCurlRes);//释放多线程curl的资源
    }


    /**
     * 相对url转绝对url
     * @param array|string $urls 待转换的url
     * @param string $referenceUrl 参照url，如果是目录后面带上/
     * @return array|string 转换后的绝对url
     */
    public static function absoluteUrl($urls, string $referenceUrl)
    {
        $urlArr=[];
        if(is_string($urls))
        {
            $urlArr[0]=$urls;
        }
        elseif(is_array($urls))
        {
            $urlArr=$urls;
        }
        //语法解析参照url
        $referenceUrlInfo=parse_url($referenceUrl);
        foreach($urlArr as &$url)
        {
            $url=trim($url);//去除一下前后空白字符
            //语法解析url
            $urlInfo=parse_url($url);
            //如果url带了协议，如http、https或者以//起头不做处理
            if(isset($urlInfo['scheme']) || substr($url,0,2)=='//') {
                continue;
            }
            //如果以"/""起头，前面加上协议、域名
            if(substr($urlInfo['path'],0,1)=='/')
            {
                $url=$referenceUrlInfo['scheme'].'://'.$referenceUrlInfo['host'].$url;
            }
            //其他情况
            else
            {
                if(isset($referenceUrlInfo['path']))
                {
                    $pathParts=explode('/',$referenceUrlInfo['path']);
                    array_pop($pathParts);
                    $path=implode('/',$pathParts).'/';
                    $url=$referenceUrlInfo['scheme'].'://'.$referenceUrlInfo['host'].$path.$url;
                }
                else
                {
                    $url=$referenceUrl.'/'.$url;
                }
            }
        }

        //处理url path中的..和.
        foreach($urlArr as &$url)
        {
            //语法解析url
            $urlInfo=parse_url($url);
            if(isset($urlInfo['path']))
            {
                //将url截取
                //获取截取开始点
                $index=strlen($urlInfo['scheme'].'://'.$urlInfo['host']);
                $subUrl=substr($url,$index);
                $subUrlParts=explode('/',$subUrl);
                $subUrlPartsFiltered=[];
                foreach($subUrlParts as $part)
                {
                    if($part=='..')
                    {
                        array_pop($subUrlPartsFiltered);
                    }
                    elseif($part=='.')
                    {
                        continue;
                    }
                    else
                    {
                        array_push($subUrlPartsFiltered,$part);
                    }
                }

                $url=$urlInfo['scheme'].'://'.$urlInfo['host'].implode('/',$subUrlPartsFiltered);

            }
            else
            {
                continue;
            }
        }

        if(is_string($urls))
        {
            return $urlArr[0];
        }
        elseif(is_array($urls))
        {
            return $urlArr;
        }

    }
    //自己写个函数来合并数组,将第二个数组的数组项合并到第一个数组中
    //有则覆盖，无则追加，并且实现递归。
    public static  function customArrayMerge(array $baseArr,array $extraArr)
    {
        foreach($extraArr as $k=>$v)
        {

            //如果第二个数组的数组项的值不是数组，或者第一个数组没有这一项
            if((!is_array($v)) || (!isset($baseArr[$k])))
            {
                $baseArr[$k]=$v;
            }
            else //如果是数组，需要递归
            {
                $baseArr[$k]=self::customArrayMerge($baseArr[$k],$v);
            }
        }
        return $baseArr;
    }


}














