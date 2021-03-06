<?php

namespace Topxia\Service\IM\Impl;

use Topxia\Common\ArrayToolkit;
use Topxia\Service\Common\BaseService;
use Topxia\Service\IM\ConversationService;
use Topxia\Service\CloudPlatform\IMAPIFactory;

class ConversationServiceImpl extends BaseService implements ConversationService
{
    private $imApi;

    public function getConversation($id)
    {
        return $this->getConversationDao()->getConversation($id);
    }

    public function getConversationByConvNo($convNo)
    {
        return $this->getConversationDao()->getConversationByConvNo($convNo);
    }

    public function getConversationByMemberIds(array $memberIds)
    {
        sort($memberIds);
        $memberHash = $this->buildMemberHash($memberIds);
        return $this->getConversationDao()->getConversationByMemberHash($memberHash);
    }

    public function getConversationByTarget($targetId, $targetType)
    {
        return $this->getConversationDao()->getConversationByTargetIdAndTargetType($targetId, $targetType);
    }

    public function createConversation($title, $targetType, $targetId, $members)
    {
        $conversation               = array();
        $conversation['title']      = $title;
        $conversation['targetType'] = $targetType;
        $conversation['targetId']   = empty($targetId) ? 0 : intval($targetId);

        $memberIds = ArrayToolkit::column($members, 'id');
        if ($targetType == 'private') {
            $conversation['title']     = join(ArrayToolkit::column($members, 'nickname'), '-').'的私聊';
            $conversation['memberIds'] = $memberIds;
        } else {
            $conversation['memberIds'] = array();
        }

        $lockName = "im_{$conversation['targetType']}{$conversation['targetId']}";
        if ($targetType == 'global') {
            $lockName = "im_{$conversation['targetType']}0";
        }

        $lockResult = $this->getLock()->get($lockName, 50);
        if (!$lockResult) {
            throw $this->createServiceException($this->trans('创建会话失败'), '700004');
        }

        $convNo = $this->createCloudConversation($conversation['title'], $members);

        $conversation['no'] = $convNo;

        $conversation = $this->addConversation($conversation);

        $this->getLock()->release($lockName);

        if ($targetType != 'private') {
            foreach ($members as $member) {
                $this->addMember(array(
                    'convNo'     => $conversation['no'],
                    'targetType' => $conversation['targetType'],
                    'targetId'   => $conversation['targetId'],
                    'userId'     => $member['id']
                ));
            }
        }

        return $conversation;
    }

    public function createCloudConversation($title, $members)
    {
        if (!$members) {
            throw $this->createServiceException($this->trans('会话用户不存在'), '700009');
        }

        $clients = array();
        foreach ($members as $member) {
            $clients[] = array('clientId' => $member['id'], 'clientName' => $member['nickname']);
        }

        $message = array(
            'name'    => $title,
            'clients' => $clients
        );

        $result = $this->createImApi()->post('/me/conversation', $message);

        if (isset($result['error'])) {
            throw $this->createServiceException($result['error'], $result['error']['code']);
        }

        return $result['no'];
    }

    public function addConversation($conversation)
    {
        $conversation = $this->filterConversationFields($conversation);

        $conversation['memberHash'] = $this->buildMemberHash($conversation['memberIds']);
        if (empty($conversation['memberIds'])) {
            $conversation['memberHash'] = '';
        }

        $conversation['createdTime'] = time();

        return $this->getConversationDao()->addConversation($conversation);
    }

    public function searchConversations($conditions, $orderBy, $start, $limit)
    {
        return $this->getConversationDao()->searchConversations($conditions, $orderBy, $start, $limit);
    }

    public function searchConversationCount($conditions)
    {
        return $this->getConversationDao()->searchConversationCount($conditions);
    }

    public function deleteConversationByTargetIdAndTargetType($targetId, $targetType)
    {
        return $this->getConversationDao()->deleteConversationByTargetIdAndTargetType($targetId, $targetType);
    }

    public function getMember($id)
    {
        return $this->getConversationMemberDao()->getMember($id);
    }

    public function getMemberByConvNoAndUserId($convNo, $userId)
    {
        return $this->getConversationMemberDao()->getMemberByConvNoAndUserId($convNo, $userId);
    }

    public function findMembersByConvNo($convNo)
    {
        return $this->getConversationMemberDao()->findMembersByConvNo($convNo);
    }

    public function findMembersByUserIdAndTargetType($userId, $targetType)
    {
        return $this->getConversationMemberDao()->findMembersByUserIdAndTargetType($userId, $targetType);
    }

    public function addMember($member)
    {
        $member['createdTime'] = time();
        return $this->getConversationMemberDao()->addMember($member);
    }

    public function deleteMember($id)
    {
        return $this->getConversationMemberDao()->deleteMember($id);
    }

    public function deleteMemberByConvNoAndUserId($convNo, $userId)
    {
        return $this->getConversationMemberDao()->deleteMemberByConvNoAndUserId($convNo, $userId);
    }

    public function deleteMembersByTargetIdAndTargetType($targetId, $targetType)
    {
        return $this->getConversationMemberDao()->deleteMembersByTargetIdAndTargetType($targetId, $targetType);
    }

    public function joinConversation($convNo, $userId)
    {
        $conv = $this->getConversationByConvNo($convNo);

        if (!$conv) {
            throw $this->createServiceException($this->trans('会话不存在'), '700007');
        }

        $user = $this->getUserService()->getUser($userId);

        $added = $this->addConversationMember($conv['no'], array(array('id' => $user['id'], 'nickname' => $user['nickname'])));
        if (!$added) {
            throw $this->createServiceException($this->trans('学员进入会话失败'), '700006');
        }

        $member = array(
            'convNo'     => $conv['no'],
            'targetId'   => $conv['targetId'],
            'targetType' => $conv['targetType'],
            'userId'     => $user['id']
        );

        return $this->addMember($member);
    }

    public function quitConversation($convNo, $userId)
    {
        $conv = $this->getConversationByConvNo($convNo);

        if (!$conv) {
            throw $this->createServiceException($this->trans('会话不存在'), '700007');
        }

        $convMember = $this->getMemberByConvNoAndUserId($convNo, $userId);
        if (!$convMember) {
            throw $this->createServiceException($this->trans('会话成员不存在'), '700011');
        }

        $removed = $this->removeConversationMember($convNo, $userId);

        if (!$removed) {
            throw $this->createServiceException($this->trans('学员退出会话失败'), '700010');
        }

        $this->deleteMember($convMember['id']);

        return true;
    }

    public function addConversationMember($convNo, $members)
    {
        if (empty($members)) {
            return false;
        }

        $clients = array();
        foreach ($members as $member) {
            $clients[] = array('clientId' => $member['id'], 'clientName' => $member['nickname']);
        }

        $res = $this->createImApi()->post("/conversations/{$convNo}/members", array('clients' => $clients));

        if (isset($res['success'])) {
            return true;
        }

        return false;
    }

    public function removeConversationMember($convNo, $userId)
    {
        $res = $this->createImApi()->delete("/conversations/{$convNo}/members/{$userId}");

        if (isset($res['success'])) {
            return true;
        }

        return false;
    }

    public function isImMemberFull($convNo, $limit)
    {
        $result = $this->createImApi()->get("/conversations/{$convNo}/members");

        if ($result) {
            $onlineCount  = empty($result['online']) ? 0 : count($result['online']);
            $offlineCount = empty($result['offline']) ? 0 : count($result['offline']);

            if (($onlineCount + $offlineCount) >= $limit) {
                return true;
            }
        }

        return false;
    }

    public function searchMembers($conditions, $orderBy, $start, $limit)
    {
        return $this->getConversationMemberDao()->searchMembers($conditions, $orderBy, $start, $limit);
    }

    public function searchMemberCount($conditions)
    {
        return $this->getConversationMemberDao()->searchMemberCount($conditions);
    }

    protected function filterConversationFields(array $fields)
    {
        $fields = ArrayToolkit::parts($fields, array('no', 'memberIds', 'targetId', 'targetType', 'title'));

        if (empty($fields['no'])) {
            throw $this->createServiceException('field `no` can not be empty');
        }

        if (!is_array($fields['memberIds'])) {
            throw $this->createServiceException('field `memberIds` must be array');
        }

        sort($fields['memberIds']);

        return $fields;
    }

    protected function buildMemberHash(array $memberIds)
    {
        return md5(join($memberIds, ','));
    }

    protected function filterMyConversationFields(array $fields)
    {
        $fields = ArrayToolkit::parts($fields, array(
            'no',
            'userId',
            'createdTime',
            'updatedTime'
        ));

        if (empty($fields['no'])) {
            throw $this->createServiceException('field `no` can not be empty');
        }

        if (empty($fields['userId'])) {
            throw $this->createServiceException('field `userId` can not be empty');
        }

        return $fields;
    }

    protected function getConversationDao()
    {
        return $this->createDao('IM.ConversationDao');
    }

    protected function getConversationMemberDao()
    {
        return $this->createDao('IM.ConversationMemberDao');
    }

    protected function getCourseService()
    {
        return $this->createService('Course.CourseService');
    }

    protected function getClassroomService()
    {
        return $this->createService('Classroom:Classroom.ClassroomService');
    }

    protected function getUserService()
    {
        return $this->createService('User.UserService');
    }

    protected function createImApi()
    {
        if (!$this->imApi) {
            $this->imApi = IMAPIFactory::create();
        }
        return $this->imApi;
    }

    /**
     * 仅给单元测试mock用。
     */
    public function setImApi($imApi)
    {
        $this->imApi = $imApi;
    }
}
