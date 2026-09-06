<?php

namespace App\Enums;

/**
 * The things a person does that the Activity screen shows back to them.
 *
 * Every one of these is the account owner's own action. Anything somebody
 * else does to them is a notification instead — see NotificationType, and
 * the note on the activity_log migration for why the two are separate.
 *
 * `tool` is what the screen's pills filter on, so a new action has to name
 * one; there is no "other" bucket, because a row nobody can filter to is a
 * row nobody finds.
 */
enum ActivityAction: string
{
    case Upload = 'upload';
    case Version = 'version';
    case FolderCreate = 'folder.create';
    case FolderDelete = 'folder.delete';
    case Move = 'move';
    case Trash = 'trash';
    case Restore = 'restore';
    case Purge = 'purge';
    case Transfer = 'transfer';
    case Download = 'download';
    case RequestCreate = 'request.create';
    case SpacePublish = 'space.publish';
    case SpaceArchive = 'space.archive';
    case PostSchedule = 'post.schedule';
    case PostPublish = 'post.publish';

    /** Which pill on the Activity screen this belongs under. */
    public function tool(): string
    {
        return match ($this) {
            self::Upload, self::Version, self::FolderCreate, self::FolderDelete,
            self::Move, self::Trash, self::Restore, self::Purge, self::Transfer,
            self::Download, self::RequestCreate => 'Files',
            self::SpacePublish, self::SpaceArchive => 'Spaces',
            self::PostSchedule, self::PostPublish => 'Posts',
        };
    }
}
