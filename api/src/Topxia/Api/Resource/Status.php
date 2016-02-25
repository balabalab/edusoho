<?php

namespace Topxia\Api\Resource;

use Silex\Application;
use Topxia\Common\ArrayToolkit;
use Symfony\Component\HttpFoundation\Request;

class Status extends BaseResource
{
    public function get(Application $app, Request $request, $courseId)
    {
        try {
            $this->getCourseService()->tryTakeCourse($courseId);
        } catch (\Exception $e) {
            return $this->error(404, "课程(#{$courseId})不是用户(#{$user['id']})的所学课程");
        }

        $start = $request->query->get('start', 0);
        $limit = $request->query->get('limit', 10);

        $statuses = $this->getStatusService()->searchStatuses(array('userId' => $user['id'], 'courseId' => $courseId), array('createdTime', 'DESC'), $start, $limit);

        return $this->_filterStatus($statuses);
    }

    public function filter(&$res)
    {
        $res = ArrayToolkit::parts($res, array('id', 'userId', 'courseId', 'classroomId', 'type', 'objectType', 'objectId', 'properties', 'createdTime'));

        return $res;
    }

    private function _filterStatus(&$res)
    {
        foreach ($res as $key => &$item) {
            unset($item['private']);
            unset($item['commentNum']);
            unset($item['likeNum']);
        }

        return $res;
    }

    protected function getStatusService()
    {
        return $this->getServiceKernel()->createService('User.StatusService');
    }

    protected function getUserService()
    {
        return $this->getServiceKernel()->createService('User.UserService');
    }

    protected function getCourseService()
    {
        return $this->getServiceKernel()->createService('Course.CourseService');
    }
}
