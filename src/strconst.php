<?php
/**
 * Created by PhpStorm.
 * User: yu.fu
 * Date: 2016/9/8
 * Time: 17:56
 */
namespace src;

class strconst
{
    const MODE_WAITRESULT = 0;
    const MODE_NORESULT = 1;
//    const MODE_ASYNCRESULT = 2;

    //sync wait task result
    //任务下发后阻塞等待结果
    const MODE_NEEDRESULT_SINGLE = 'W_S';
    const MODE_NEEDRESULT_MULTI = 'W_M';

    //async no need task result
    const MODE_NORESULT_SINGLE = 'AN_S';
    const MODE_NORESULT_MULTI = 'AN_M';

//    //async send task and at end of code manual get result
//    const MODE_ASYNCRESULT_SINGLE = 'AM_S';
//    const MODE_ASYNCRESULT_MULTI = 'AM_M';

    //recv超时
    const RECIVE_TIMEOUT = 3.0;
}
