<?php

namespace FlatRate\SupabaseOAuth\Identity;

use Flarum\Database\AbstractModel;
use Flarum\User\User;

/**
 * Extension-owned Community member-number + display-choice row.
 *
 * member_number is always users.id. It is not an independent sequence.
 */
class MemberProfile extends AbstractModel
{
    protected $table = 'flatrate_member_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'member_number',
        'display_mode',
        'custom_nickname',
        'custom_nickname_origin',
        'assigned_at',
        'updated_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
