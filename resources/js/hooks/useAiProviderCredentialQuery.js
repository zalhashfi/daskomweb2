import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";

export const AI_PROVIDER_CREDENTIAL_QUERY_KEY = ["ai-provider-credentials"];

const fetchCredential = async () => {
    const { data } = await api.get("/api-v1/ai-provider-credentials");

    if (data?.status === "success") {
        return data.data;
    }

    if (data?.success === false) {
        throw new Error(data?.message ?? "Gagal memuat konfigurasi provider AI");
    }

    return null;
};

export const useAiProviderCredentialQuery = (options = {}) =>
    useQuery({
        queryKey: AI_PROVIDER_CREDENTIAL_QUERY_KEY,
        queryFn: fetchCredential,
        staleTime: 30 * 1000,
        ...options,
    });
