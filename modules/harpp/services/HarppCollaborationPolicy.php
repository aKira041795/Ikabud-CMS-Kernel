<?php

declare(strict_types=1);

namespace Harpp\Services;

/** Pure policy functions keep authorization and approval rules deterministic and testable. */
final class HarppCollaborationPolicy
{
    public static function validRoles(array $roles): bool
    {
        $allowed=['manager','operator','reviewer','viewer'];
        return $roles!==[] && count($roles)===count(array_unique($roles)) && array_diff($roles,$allowed)===[];
    }

    public static function canSee(string $visibility, int $actorId, int $creatorId, bool $workspaceMember, bool $projectMember, bool $participant, bool $privateGrant, bool $breakGlass=false): bool
    {
        if($actorId<=0)return false;
        if($breakGlass)return true;
        if($actorId===$creatorId)return $workspaceMember && $projectMember;
        return match($visibility){
            'workspace'=>$workspaceMember && $projectMember,
            'participants'=>$participant && $workspaceMember && $projectMember,
            'private'=>$privateGrant && $workspaceMember && $projectMember,
            default=>false,
        };
    }

    public static function approvalSatisfied(array $policy, array $votes, int $creatorId, ?int $executorId=null): bool
    {
        $approvers=[];
        foreach($votes as $vote){$user=(int)($vote['user_id']??0);if(($vote['vote']??'')==='veto'&&!empty($policy['allow_veto']))return false;if(($vote['vote']??'')!=='approve'||$user<=0)continue;if(!empty($policy['exclude_creator'])&&$user===$creatorId)continue;if(!empty($policy['exclude_executor'])&&$executorId!==null&&$user===$executorId)continue;$approvers[$user]=true;}
        return count($approvers)>=max(1,(int)($policy['quorum']??1));
    }
}
