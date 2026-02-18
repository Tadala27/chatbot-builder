// Checks if a value is empty
export function isEmpty(value: unknown): boolean {
  if (value === null || value === undefined || value === '')
    return true

  return !!(Array.isArray(value) && value.length === 0)
}

// Checks if a value is null or undefined
export function isNullOrUndefined(value: unknown): value is undefined | null {
  return value === null || value === undefined
}

// Checks if an array is empty
export function isEmptyArray(array: unknown): boolean {
  return Array.isArray(array) && array.length === 0
}
