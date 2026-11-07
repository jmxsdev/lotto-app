/**
 * Decides whether a user update needs a reason and which audited action it maps to.
 *
 * Mirrors the backend `UserController::update` detection:
 * - role changes compare the full role set (`rolesBefore !== [$request->role]`),
 *   so a multi-role user always needs a reason;
 * - an admin password reset needs a reason;
 * - deactivating an active user needs a reason.
 *
 * Priority: role change > password reset > deactivation.
 */
export interface UserReasonRequirement {
  required: true;
  action: string;
  title: string;
}

export interface UserChanges {
  role?: string;
  active?: boolean;
  password?: string;
}

export function userUpdateReason(
  original: any,
  changes: UserChanges,
): UserReasonRequirement | null {
  const rawRoles = Array.isArray(original?.roles) && original.roles.length
    ? original.roles
    : [original?.role];
  const roles = rawRoles
    .map((entry: any) => (typeof entry === 'string' ? entry : entry?.name))
    .filter((name: any): name is string => typeof name === 'string' && name.length > 0);

  if (changes.role !== undefined && (roles.length > 1 || roles[0] !== changes.role)) {
    return { required: true, action: 'user.role_changed', title: 'Cambiar rol del usuario' };
  }

  if (typeof changes.password === 'string' && changes.password.trim() !== '') {
    return { required: true, action: 'user.password_reset_by_admin', title: 'Restablecer contraseña' };
  }

  if (original?.active === true && changes.active === false) {
    return { required: true, action: 'user.deactivated', title: 'Desactivar usuario' };
  }

  return null;
}
