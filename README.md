## 长时间抓取需要考虑的问题

一般默认8小时后数据库连接将会断开，如果任务跑的时间特别长
考虑修改mysql数据库配置的interactive_timeout和 wait_timeout

## 抓取回调函数自动传入的参数情况

+ 静态方法mCrawl()用的回调函数没有中括号[]里面的部分，也没有返回值

+ curl信息数组里面有什么可以看这个页面的"Return Values"部分 https://www.php.net/manual/en/function.curl-getinfo.php

+ **onSuccess**    p1:curl信息数组 p2:抓取到的内容 [p3:自定义信息数组] [p4:pdo对象] 这个回调函数主要有两个作用：①对抓取到的内容进行分析，提取出自己想要的数据保存到数据库中②可以返回一个数组，里面的url会成为下一级别的抓取任务。形式：
    + [ [url,url,......],false ]  这种形式默认extraInfo为null
    + [ [[url,extraInfo],[url,extraInfo],...... ],false ] 
    + 上面的extraInfo是个关联数组
    + 上面false改为true可临时无视布隆过滤器

③返回\"reset\"重置任务，\"ignore\"跳过任务

+ **onSuccessWithUnexpectedCode** p1:curl信息数组 [p2:自定义信息数组] [p3:pdo对象]

+ **onFail** p1:目标url p2:错误消息 p3:错误码 [p4：自定义信息数组] [p5:pdo对象]

## 数据去重(布隆过滤器)

针对每个抓取的目标网站建立一个历史url集合，对即将添加到抓取任务表的url判断是否在这个集合中，没在才添加，并将这个新的url加入到集合中。

技术上采用布隆过滤器，bloomfilter（布隆过滤器）：时间和空间效率极高，有误判“结果在集合中，但实际不在集合中”，“结果不在集合中，实际也一定不在集合中”。所以使用bloomfilter最大的损失也就是有一些页面漏抓而已，这是完全可以承受的。

 项目地址： https://github.com/pleonasm/bloom-filter 使用时直接参考即可
 
 ## 监视器
 
  对监视任务表中的任务进行监视（周期抓取），抓取成功后调用回调函数，回调函数中对页面是否更新进行判断。
  
  如果页面更新了，返回数组(形式同onSuccess的返回值)则向抓取任务表中追加新的任务(任务级别加1)，返回true则更新抓取任务表中与当前监视任务对应的任务为未抓取(0)状态以便再次抓取。
  
## 监视回调函数自动传入的参数情况

+ **onMonitorSuccess**    p1:curl信息数组 p2:抓取到的内容 p3:自定义信息数组 [p4:pdo对象 p5:布隆过滤器对象]

## 配置数组的配置项说明

```php

$config=[

        //创建布隆过滤器时使用的预估条目数(确保添加到布隆过滤器中的条目数小于这个值)
        'item_num'=>200000,
        
        //创建布隆过滤器时使用的误判几率
        'probability'=>0.0001,
        
        //无视布隆过滤器，开启时添加任务到任务表不会受到布隆过滤器的阻止
        'ignore_bloom_filter'=>false,
        
        //是否启用tor代理 这部分功能与 https://github.com/trimstray/multitor 项目配合使用
        //必须保证当前环境multitor已经正确安装配置并且已经创建了若干tor进程
        'use_tor'=>false,
        
        //tor进程端口范围
        'tor_ports_range'=>[20000,20009],


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
```

