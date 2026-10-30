<?php

/**
 * SPDX-FileCopyrightText: 2026 Jonathan Läpple and VisitorPortal contributors
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace App\Support;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class VisitorPortalPermissions
{
    /**
     * @var list<string>
     */
    private const STANDALONE_PERMISSIONS = [
        'LoginLocallyInSsoOnlyMode',
    ];

    /**
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        $userPermissions = [
            'ViewOwn:User',
            'EditOwn:User',
            'Create:Visit',
            'Create:Visitor',
            'ViewKnown:Visitor',
            'ViewOwn:Visit',
            'EditOwn:Visit',
            'DeleteOwn:Visit',
            'CancelOwn:Visit',
            'View:Role',
        ];

        $managerPermissions = self::unique(array_merge($userPermissions, [
            'ViewDepartment:User',
            'EditDepartment:User',
            'ViewDepartment:Visit',
            'EditDepartment:Visit',
            'DeleteDepartment:Visit',
            'CreateForDepartment:Visit',
            'ViewDepartment:Visitor',
        ]));

        $receptionistPermissions = self::unique(array_merge(array_diff($userPermissions, [
            'EditOwn:Visit',
            'DeleteOwn:Visit',
            'CancelOwn:Visit',
        ]), [
            'ViewSite:Visit',
            'CreateForSite:Visit',
            'CancelSite:Visit',
            'ViewSite:Visitor',
            'ViewContactDetails:Visitor',
            'UpdateKnown:Visitor',
            'CheckIn:Visitor',
            'CheckOut:Visitor',
            'Print:Visitor',
            'View:Monitor',
            'ViewSite:Monitor',
            'Edit:Monitor',
            'ManageSite:Monitor',
            'Create:Monitor',
            'ViewAny:MonitorSlide',
            'View:MonitorSlide',
            'Create:MonitorSlide',
            'Update:MonitorSlide',
            'Delete:MonitorSlide',
            'Delete:Monitor',
        ]));

        $adminPermissions = self::unique(array_merge($receptionistPermissions, [
            'ViewAny:User',
            'View:User',
            'Create:User',
            'Update:User',
            'Delete:User',
            'DeleteAny:User',
            'ManageAny:User',
            'ViewDepartment:User',
            'EditDepartment:User',
            'DeleteDepartment:User',
            'DeactivateAny:User',
            'DeactivateDepartment:User',
            'EditAny:User',
            'ViewAny:Role',
            'Create:Role',
            'Update:Role',
            'Delete:Role',
            'ViewAny:Department',
            'View:Department',
            'Create:Department',
            'Update:Department',
            'Delete:Department',
            'ViewAny:Site',
            'Create:Site',
            'Update:Site',
            'Delete:Site',
            'ManageAny:Site',
            'View:Visitor',
            'Update:Visitor',
            'UpdateAny:Visitor',
            'Delete:Visitor',
            'DeleteAny:Visitor',
            'ViewAny:Visitor',
            'EditAny:Visitor',
            'View:Visit',
            'Update:Visit',
            'Delete:Visit',
            'ViewAny:Visit',
            'EditAny:Visit',
            'DeleteAny:Visit',
            'CancelAny:Visit',
            'CancelDepartment:Visit',
            'CreateForAny:Visit',
            'ViewArchive:Visit',
            'ManageAny:Monitor',
            'Update:Monitor',
            'Restore:User',
            'ForceDelete:User',
            'ForceDeleteAny:User',
            'RestoreAny:User',
            'Replicate:User',
            'Reorder:User',
            'Restore:Role',
            'ForceDelete:Role',
            'ForceDeleteAny:Role',
            'RestoreAny:Role',
            'Replicate:Role',
            'Reorder:Role',
            'Restore:Department',
            'ForceDelete:Department',
            'ForceDeleteAny:Department',
            'RestoreAny:Department',
            'Replicate:Department',
            'Reorder:Department',
            'Restore:Visitor',
            'ForceDelete:Visitor',
            'ForceDeleteAny:Visitor',
            'RestoreAny:Visitor',
            'Replicate:Visitor',
            'Reorder:Visitor',
            'Restore:Visit',
            'ForceDelete:Visit',
            'ForceDeleteAny:Visit',
            'RestoreAny:Visit',
            'Replicate:Visit',
            'Reorder:Visit',
            'View:Dashboard',
            'ForceDelete:MonitorSlide',
            'ForceDeleteAny:MonitorSlide',
            'Reorder:MonitorSlide',
            'Replicate:MonitorSlide',
            'Restore:MonitorSlide',
            'RestoreAny:MonitorSlide',
            'View:UserPermissions',
            'ViewAny:Worker',
            'View:Worker',
            'Create:Worker',
            'Update:Worker',
            'Delete:Worker',
            'DeleteAny:Worker',
            'ViewAny:MovementReason',
            'View:MovementReason',
            'Create:MovementReason',
            'Update:MovementReason',
            'Delete:MovementReason',
            'DeleteAny:MovementReason',
            'ViewAny:Destination',
            'View:Destination',
            'Create:Destination',
            'Update:Destination',
            'Delete:Destination',
            'DeleteAny:Destination',
            'ViewAny:Movement',
            'View:Movement',
            'ViewAny:Absence',
            'View:Absence',
            'Create:Absence',
            'Update:Absence',
            'Delete:Absence',
            'DeleteAny:Absence',
            'View:GateReport',
            'ViewAny:FleetVehicle',
            'View:FleetVehicle',
            'Create:FleetVehicle',
            'Update:FleetVehicle',
            'Delete:FleetVehicle',
            'DeleteAny:FleetVehicle',
            'ViewAny:StaffVehicle',
            'View:StaffVehicle',
            'Create:StaffVehicle',
            'Update:StaffVehicle',
            'Delete:StaffVehicle',
            'DeleteAny:StaffVehicle',
        ]));

        $gateReadPermissions = [
            'ViewAny:Movement',
            'View:Movement',
            'ViewAny:Worker',
            'View:Worker',
        ];

        $vehicleReadPermissions = [
            'ViewAny:FleetVehicle',
            'View:FleetVehicle',
            'ViewAny:StaffVehicle',
            'View:StaffVehicle',
        ];

        $absenceReadPermissions = [
            'ViewAny:Absence',
            'View:Absence',
            'View:GateReport',
        ];

        $catalogReadPermissions = [
            'ViewAny:MovementReason',
            'View:MovementReason',
            'ViewAny:Destination',
            'View:Destination',
        ];

        // The guard keeps the reception visit tools but not the monitors or cancelling visits.
        $guardPermissions = self::unique(array_merge(
            array_diff($receptionistPermissions, [
                'CancelSite:Visit',
                'View:Monitor',
                'ViewSite:Monitor',
                'Edit:Monitor',
                'ManageSite:Monitor',
                'Create:Monitor',
                'Delete:Monitor',
                'ViewAny:MonitorSlide',
                'View:MonitorSlide',
                'Create:MonitorSlide',
                'Update:MonitorSlide',
                'Delete:MonitorSlide',
            ]),
            ['Create:Movement'],
        ));

        $gateSupervisorPermissions = self::unique(array_merge($guardPermissions, $gateReadPermissions, $catalogReadPermissions, $absenceReadPermissions, $vehicleReadPermissions, [
            'Correct:Movement',
            'Create:FleetVehicle',
            'Update:FleetVehicle',
            'Create:StaffVehicle',
            'Update:StaffVehicle',
        ]));

        $hrPermissions = self::unique(array_merge($userPermissions, $gateReadPermissions, $absenceReadPermissions, [
            'ViewAny:StaffVehicle',
            'View:StaffVehicle',
            'Create:StaffVehicle',
            'Update:StaffVehicle',
            'Create:Worker',
            'Update:Worker',
            'Create:Absence',
            'Update:Absence',
            'Delete:Absence',
            'DeleteAny:Absence',
        ]));

        $auditorPermissions = self::unique(array_merge($userPermissions, $gateReadPermissions, $catalogReadPermissions, $absenceReadPermissions, $vehicleReadPermissions, [
            'ViewAny:Visit',
            'View:Visit',
            'ViewAny:Visitor',
            'View:Visitor',
        ]));

        return [
            'admin' => $adminPermissions,
            'guard' => $guardPermissions,
            'gate-supervisor' => $gateSupervisorPermissions,
            'hr' => $hrPermissions,
            'auditor' => $auditorPermissions,
            'user' => $userPermissions,
            'manager' => $managerPermissions,
            'receptionist' => $receptionistPermissions,
            'welcome monitor' => [
                'View:Monitor',
                'ViewSite:Monitor',
                'View:MonitorSlide',
                'ViewAny:MonitorSlide',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return self::unique(array_merge(self::STANDALONE_PERMISSIONS, ...array_values(self::roles())));
    }

    public static function sync(?Command $command = null): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (self::roles() as $roleName => $permissions) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])
                ->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $command?->info('VisitorPortal roles and permissions synchronized.');
    }

    /**
     * @param  list<string>  $items
     * @return list<string>
     */
    private static function unique(array $items): array
    {
        return array_values(array_unique($items));
    }
}
