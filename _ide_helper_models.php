<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AdditionType withoutTrashed()
 */
	class AdditionType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ColorVisionType withoutTrashed()
 */
	class ColorVisionType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string $name
 * @property string|null $company_name
 * @property string|null $national_registry
 * @property string|null $ans_registry
 * @property string|null $color
 * @property bool $table
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereAnsRegistry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereCompanyName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereNationalRegistry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereTable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Covenant withoutTrashed()
 */
	class Covenant extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $abbreviation
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereAbbreviation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CoverTestType withoutTrashed()
 */
	class CoverTestType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $person_id
 * @property string $code
 * @property string|null $record
 * @property string|null $record_specialty
 * @property string|null $color
 * @property bool $partner
 * @property bool $active
 * @property string|null $observation
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $entity_user_id
 * @property-read \App\Models\EntityUser $entityUser
 * @property-read \App\Models\People $person
 * @method static \Database\Factories\DoctorFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereEntityUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereObservation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor wherePartner($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor wherePersonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereRecord($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereRecordSpecialty($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Doctor withoutTrashed()
 */
	class Doctor extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $subdomain
 * @property string|null $zipcode
 * @property string|null $address
 * @property string|null $number
 * @property string|null $complement
 * @property string|null $district
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string $national_registration
 * @property string|null $state_registration
 * @property string|null $municipal_registration
 * @property string|null $telephone
 * @property string|null $cellphone
 * @property string|null $email
 * @property string|null $website
 * @property string|null $logo
 * @property bool $is_client
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string $locale
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EntityUser> $entityUsers
 * @property-read int|null $entity_users_count
 * @method static \Database\Factories\EntityFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereCellphone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereComplement($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereDistrict($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereIsClient($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereMunicipalRegistration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereNationalRegistration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereStateRegistration($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereSubdomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereTelephone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereWebsite($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity whereZipcode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Entity withoutTrashed()
 */
	class Entity extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $entity_user_integrator_id
 * @property string $code
 * @property string $name
 * @property string|null $ip
 * @property string $mac
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EntityIntegratorEquipment> $equipments
 * @property-read int|null $equipments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @property-read \App\Models\EntityUserIntegrator $user
 * @method static \Database\Factories\EntityIntegratorFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereEntityUserIntegratorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereMac($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegrator withoutTrashed()
 */
	class EntityIntegrator extends \Eloquent {}
}

namespace App\Models{
/**
 * @property-read \App\Models\EntityIntegrator|null $integrator
 * @property-read mixed $mac
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegratorEquipment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegratorEquipment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegratorEquipment onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegratorEquipment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegratorEquipment withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityIntegratorEquipment withoutTrashed()
 */
	class EntityIntegratorEquipment extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $entity_id
 * @property string $user_id
 * @property string $code
 * @property bool $active
 * @property string $rule
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Models\Doctor|null $doctor
 * @property-read \App\Models\Entity $entity
 * @property-read \App\Models\User $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereRule($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUser withoutTrashed()
 */
	class EntityUser extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property bool $active
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity $entity
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EntityIntegrator> $integrators
 * @property-read int|null $integrators_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Laravel\Sanctum\PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 * @method static \Database\Factories\EntityUserIntegratorFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EntityUserIntegrator withoutTrashed()
 */
	class EntityUserIntegrator extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property \App\Enums\ExamCategory $category
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExamType withoutTrashed()
 */
	class ExamType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IrisType withoutTrashed()
 */
	class IrisType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $away
 * @property bool $near
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereAway($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereNear($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Lense withoutTrashed()
 */
	class Lense extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|NearPointConvergence withoutTrashed()
 */
	class NearPointConvergence extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $entity_id
 * @property string $person_id
 * @property string $covenant_id
 * @property string|null $skin_id
 * @property string|null $iris_id
 * @property string $code
 * @property string|null $card_number
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Covenant $covenant
 * @property-read \App\Models\Entity $entity
 * @property-read \App\Models\IrisType|null $irisType
 * @property-read \App\Models\People $person
 * @property-read \App\Models\SkinType|null $skinType
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereCardNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereCovenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereIrisId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient wherePersonId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereSkinId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Patient withoutTrashed()
 */
	class Patient extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $patient_id
 * @property string|null $doctor_id
 * @property string|null $schedule_id
 * @property string $code
 * @property string $archive
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $exam_id
 * @property-read mixed $archive_url
 * @property-read \App\Models\Doctor|null $doctor
 * @property-read \App\Models\ExamType $examType
 * @property-read \App\Models\Patient $patient
 * @property-read \App\Models\Schedule|null $schedule
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereArchive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereDoctorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereExamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam wherePatientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereScheduleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatientExam whereUpdatedAt($value)
 */
	class PatientExam extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $full_name
 * @property string|null $nickname
 * @property \Illuminate\Support\Carbon|null $birth_date
 * @property int|null $gender
 * @property int|null $marital_status
 * @property string|null $email
 * @property string|null $mother_name
 * @property string|null $father_name
 * @property string|null $national_registry
 * @property string|null $state_registry
 * @property string|null $state_registry_agency
 * @property string|null $state_registry_initial
 * @property \Illuminate\Support\Carbon|null $state_registry_date
 * @property string|null $telephone
 * @property string $cellphone
 * @property bool $whatsapp
 * @property string|null $zipcode
 * @property string|null $address
 * @property string|null $number
 * @property string|null $complement
 * @property string|null $district
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string|null $photo
 * @property string|null $latitude
 * @property string|null $longitude
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @method static \Database\Factories\PeopleFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereBirthDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereCellphone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereComplement($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereDistrict($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereFatherName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereFullName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereGender($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereMaritalStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereMotherName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereNationalRegistry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People wherePhoto($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereStateRegistry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereStateRegistryAgency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereStateRegistryDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereStateRegistryInitial($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereTelephone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereWhatsapp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People whereZipcode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|People withoutTrashed()
 */
	class People extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string|null $entity_id
 * @property string $code
 * @property string $name
 * @property int|null $nomo_binocular
 * @property int|null $treatment
 * @property bool $active
 * @property string|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereNomoBinocular($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereTreatment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Procedure whereUpdatedAt($value)
 */
	class Procedure extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string $entity_id
 * @property string $doctor_id
 * @property string|null $patient_id
 * @property string|null $covenant_id
 * @property string $code
 * @property string|null $full_name
 * @property \Illuminate\Support\Carbon $date_time
 * @property string|null $telephone
 * @property string|null $cellphone
 * @property bool $cellphone_whatsapp
 * @property int $situation
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $visit_id
 * @property-read \App\Models\Covenant|null $covenant
 * @property-read \App\Models\Doctor $doctor
 * @property-read \App\Models\Entity $entity
 * @property-read \App\Models\Patient|null $patient
 * @property-read \App\Models\VisitType $visitType
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereCellphone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereCellphoneWhatsapp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereCovenantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereDateTime($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereDoctorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereFullName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule wherePatientId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereSituation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereTelephone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule whereVisitId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Schedule withoutTrashed()
 */
	class Schedule extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SkinType withoutTrashed()
 */
	class SkinType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $category
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SurgeryType withoutTrashed()
 */
	class SurgeryType extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property string|null $locale
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EntityUser> $entityUsers
 * @property-read int|null $entity_users_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTrashed()
 */
	class User extends \Eloquent implements \Illuminate\Contracts\Auth\MustVerifyEmail {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisitType withoutTrashed()
 */
	class VisitType extends \Eloquent {}
}

namespace App\Models{
/**
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuity query()
 */
	class VisualAcuity extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $id
 * @property string|null $entity_id
 * @property string $code
 * @property int $scale
 * @property string|null $name
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Entity|null $entity
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereEntityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereScale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|VisualAcuityType withoutTrashed()
 */
	class VisualAcuityType extends \Eloquent {}
}

