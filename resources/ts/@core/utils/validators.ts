import { isEmpty, isEmptyArray, isNullOrUndefined } from './helpers'

// Require Validation
// Checks if a value is empty or false
export function requiredValidator(value: unknown) {
  if (isNullOrUndefined(value) || isEmptyArray(value) || value === false)
    return 'Please fill out this field'

  return !!String(value).trim().length || 'Please fill out this field'
}

// Email Validation
// Checks if a value is a valid email address
export function emailValidator(value: unknown) {
  if (isEmpty(value))
    return true

  const re
    = /^(?:[^<>()[\]\\.,;:\s@"]+(?:\.[^<>()[\]\\.,;:\s@"]+)*|".+")@(?:\[\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\]|(?:[a-z\-\d]+\.)+[a-z]{2,})$/i

  if (Array.isArray(value))
    return value.every(value_ => re.test(String(value_))) || 'The Email field must be a valid email'

  return re.test(String(value)) || 'The Email field must be a valid email'
}

// Password Validation
// Checks if a password meets complexity requirements
export function passwordValidator(password: string) {
  const regExp = /(?=.*\d)(?=.*[a-z])(?=.*[A-Z])(?=.*[!@#$%&*()]).{8,}/

  const validPassword = regExp.test(password)

  return validPassword || 'Field must contain at least one uppercase, lowercase, special character and digit with min 8 chars'
}

// Confirm Password Validation
// Checks if the value matches the target value
export function confirmedValidator(value: string, target: string) {
  return value === target || 'The Confirm Password field confirmation does not match'
}

// Between Validation
// Checks if a value is between a specified range
export function betweenValidator(value: unknown, min: number, max: number) {
  const valueAsNumber = Number(value)

  return (Number(min) <= valueAsNumber && Number(max) >= valueAsNumber) || `Enter number between ${min} and ${max}`
}

// Integer Validation
// Checks if a value is an integer
export function integerValidator(value: unknown) {
  if (isEmpty(value))
    return true

  if (Array.isArray(value))
    return value.every(value_ => /^-?\d+$/.test(String(value_))) || 'This field must be an integer'

  return /^-?\d+$/.test(String(value)) || 'This field must be an integer'
}

// Regex Validation
// Checks if a value matches a specified regular expression
export function regexValidator(value: unknown, regex: RegExp | string): string | boolean {
  if (isEmpty(value))
    return true

  let regeX = regex
  if (typeof regeX === 'string')
    regeX = new RegExp(regeX)

  if (Array.isArray(value))
    return value.every(value_ => regexValidator(value_, regeX))

  return regeX.test(String(value)) || 'The Regex field format is invalid'
}

// Alpha Validation
// Checks if a value contains only alphabetic characters
export function alphaValidator(value: unknown) {
  if (isEmpty(value))
    return true

  return /^[A-Z]*$/i.test(String(value)) || 'The Alpha field may only contain alphabetic characters'
}

// URL Validation
// Checks if a value is a valid URL
export function urlValidator(value: unknown) {
  if (isEmpty(value))
    return true

  const re = /^https?:\/\/[^\s$.?#].\S*$/

  return re.test(String(value)) || 'URL is invalid'
}

// Length Validation
// Checks if the length of a value matches a specified length
export function lengthValidator(value: unknown, length: number) {
  if (isEmpty(value))
    return true

  return String(value).length === length || `"The length of the Characters field must be ${length} characters."`
}

// Alpha-dash Validation
// Checks if a value contains only alphanumeric characters and dashes
export function alphaDashValidator(value: unknown) {
  if (isEmpty(value))
    return true

  const valueAsString = String(value)

  return /^[\w-]*$/.test(valueAsString) || 'All Character are not valid'
}
