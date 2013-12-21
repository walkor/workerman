<?php
/**
 *  测试
 * @author walkor <worker-man@qq.com>
 */
class Blog
{
   public static function getByBlogId($blog_id)
   {
       return array(
               'blog_id'    => $blog_id,
               'title'=> 'workerman is a high performance RPC server framework for network applications implemented in PHP using libevent',
               'content'   => 'this is content ...',
               );
   }
   
   public static function getTitleListByUid($uid)
   {
       return array(
               'blog title 1',
               'blog title 2',
               'blog title 3',
               'blog title 4',
               'blog title 5',
               );
   }
}
