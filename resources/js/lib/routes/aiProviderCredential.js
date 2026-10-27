const BASE_PATH = "/api-v1/ai-provider-credentials";

export const show = () => ({ url: BASE_PATH, method: "get" });

export const test = () => ({ url: `${BASE_PATH}/test`, method: "post" });

export const save = () => ({ url: BASE_PATH, method: "post" });
