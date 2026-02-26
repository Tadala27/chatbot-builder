import { ref, Ref } from 'vue';
import Swal from 'sweetalert2';

/**
 * Composable that guards against editing published versions
 * Shows a prompt to create a new version before allowing any changes
 */
export function useEditGuard() {
  const isCheckingVersion = ref(false);

  /**
   * Check if editing is allowed, prompt to create new version if not
   * 
   * @param flowVersion - Current flow version object
   * @param onCreateVersion - Callback to create new version
   * @returns Promise<boolean> - true if editing is allowed, false if blocked
   */
  async function canEdit(
    flowVersion: Ref<any> | any,
    onCreateVersion: () => Promise<void>
  ): Promise<boolean> {
    // Get the version object (handle both ref and plain object)
    const version = 'value' in flowVersion ? flowVersion.value : flowVersion;

    // If no version or already draft, allow editing
    if (!version || version.status === 'draft') {
      return true;
    }

    // If published or locked, block and prompt
    if (version.status === 'published' || version.status === 'locked') {
      // Prevent multiple prompts
      if (isCheckingVersion.value) {
        return false;
      }

      isCheckingVersion.value = true;

      const result = await Swal.fire({
        title: 'Version is Published',
        html: `
          <div style="text-align: left;">
            <p>This version (v${version.version_number}) is currently <strong>${version.status}</strong> and cannot be edited.</p>
            <p>Would you like to create a new draft version to make changes?</p>
          </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Create New Version',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#4CAF50',
        cancelButtonColor: '#757575',
        customClass: {
          confirmButton: 'text-white',
          cancelButton: 'text-white',
        },
      });

      isCheckingVersion.value = false;

      if (result.isConfirmed) {
        // User wants to create new version
        try {
          await onCreateVersion();
          
          // After creating version, allow the edit
          Swal.fire({
            title: 'Version Created!',
            text: 'You can now make changes to the new draft version.',
            icon: 'success',
            timer: 2000,
            showConfirmButton: false,
          });
          
          return true;
        } catch (error) {
          console.error('Failed to create version:', error);
          
          Swal.fire({
            title: 'Error',
            text: 'Failed to create new version. Please try again.',
            icon: 'error',
          });
          
          return false;
        }
      }

      // User cancelled
      return false;
    }

    // Unknown status, allow editing
    return true;
  }

  /**
   * Quick check without prompting (for read-only indicators)
   */
  function isReadOnly(flowVersion: Ref<any> | any): boolean {
    const version = 'value' in flowVersion ? flowVersion.value : flowVersion;
    
    if (!version) return false;
    
    return version.status === 'published' || version.status === 'locked';
  }

  return {
    canEdit,
    isReadOnly,
    isCheckingVersion,
  };
}
