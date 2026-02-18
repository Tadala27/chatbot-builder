import axios from "axios";

let redirecting = false;

axios.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status;
    const data = error.response?.data;

    if (redirecting) {
      return Promise.reject(error);
    }

    // ------------------------------------------------
    // 403 → UNAUTHENTICATED / UNAUTHORIZED → LOGIN
    // ------------------------------------------------
    if (status === 401) {
      redirecting = true;

      localStorage.removeItem("accessToken");
      localStorage.removeItem("user-data");
      localStorage.removeItem("user-loaded");

      // Optional: preserve current page for redirect after login
      const redirect = encodeURIComponent(window.location.pathname);

      // window.location.href = `/login?redirect=${redirect}`;
      return Promise.reject(error);
    }

    // ------------------------------------------------
    // 423 → ACCOUNT / SHAREHOLDER STATE ISSUES
    // ------------------------------------------------
    if (status === 423) {
      const code = data?.code;

      const allowedCodes = [
        "ACCOUNT_DEACTIVATED",
        "SHAREHOLDER_NOT_ACTIVE",
        "NO_SHAREHOLDER_PROFILE",
      ];

      if (allowedCodes.includes(code)) {
        redirecting = true;

        localStorage.removeItem("accessToken");
        localStorage.removeItem("user-data");
        localStorage.removeItem("user-loaded");

        const params = new URLSearchParams({
          code,
          message: data?.message ?? "Account access restricted",
          status: data?.shareholder_status ?? "",
        });

        window.location.href = `/account-error?${params.toString()}`;
      }
    }

    return Promise.reject(error);
  },
);
