import { useEffect, useState } from "react";
import toast from "react-hot-toast";
import { useMutation, useQueryClient } from "@tanstack/react-query";

import { send } from "@/lib/http";
import { ModalOverlay } from "@/Components/Common/ModalPortal";
import ModalCloseButton from "@/Components/Common/ModalCloseButton";
import {
    AI_PROVIDER_CREDENTIAL_QUERY_KEY,
    useAiProviderCredentialQuery,
} from "@/hooks/useAiProviderCredentialQuery";
import * as aiProviderCredentialRoute from "@/lib/routes/aiProviderCredential";
import editIcon from "../../../../assets/nav/Icon-Konfigurasi.svg";

const getErrorMessage = (err) => {
    if (err?.response?.data?.message) {
        return err.response.data.message;
    }

    if (err?.response?.data?.errors) {
        const firstErrors = Object.values(err.response.data.errors).flat();
        if (firstErrors.length > 0) {
            return firstErrors[0];
        }
    }

    if (err?.response?.status >= 500) {
        return "Terjadi kesalahan di server. Silakan coba lagi.";
    }

    return err?.message ?? "Gagal terhubung ke server.";
};

export default function ModalKonfigurasiAi({ onClose }) {
    const [baseUrl, setBaseUrl] = useState("");
    const [apiKey, setApiKey] = useState("");
    const [reason, setReason] = useState("");
    const [connectionResult, setConnectionResult] = useState(null);

    const queryClient = useQueryClient();

    const { data: credential, isLoading } = useAiProviderCredentialQuery({
        onError: (err) => toast.error(getErrorMessage(err)),
    });

    useEffect(() => {
        if (!credential) {
            return;
        }

        setBaseUrl(credential.base_url ?? "");
    }, [credential]);

    const testMutation = useMutation({
        mutationFn: async () => {
            const { data } = await send(aiProviderCredentialRoute.test(), {
                base_url: baseUrl,
                api_key: apiKey,
                reason: reason.length >= 10 ? reason : "Uji koneksi provider AI",
            });

            return data;
        },
        onSuccess: (data) => {
            setConnectionResult({
                reachable: Boolean(data?.data?.reachable),
                models: data?.data?.models ?? null,
            });
            toast.success("Koneksi ke provider AI berhasil.");
        },
        onError: (err) => {
            setConnectionResult({ reachable: false });
            toast.error(getErrorMessage(err));
        },
    });

    const saveMutation = useMutation({
        mutationFn: async () => {
            const { data } = await send(aiProviderCredentialRoute.save(), {
                base_url: baseUrl,
                api_key: apiKey,
                reason,
            });

            return data;
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: AI_PROVIDER_CREDENTIAL_QUERY_KEY,
            });
            toast.success("Kredensial provider AI berhasil disimpan.");
            setApiKey("");
            setReason("");
            onClose();
        },
        onError: (err) => toast.error(getErrorMessage(err)),
    });

    const isBusy = testMutation.isPending || saveMutation.isPending;
    const canSave = baseUrl.trim() !== "" && apiKey.trim() !== "" && reason.trim().length >= 10;

    return (
        <ModalOverlay onClose={onClose}>
            <div className="depth-modal-container" style={{ maxWidth: "var(--depth-modal-width-xl, 20vw)" }}>
                <div className="depth-modal-header">
                    <h2 className="depth-modal-title flex items-center gap-2">
                        <img className="edit-icon-filter h-6 w-6" src={editIcon} alt="provider AI" /> Provider AI
                    </h2>
                    <ModalCloseButton onClick={onClose} ariaLabel="Tutup konfigurasi provider AI" />
                </div>

                {isLoading ? (
                    <div className="py-10 text-center font-semibold text-depth-secondary">
                        Memuat konfigurasi provider...
                    </div>
                ) : (
                    <>
                        <div className="space-y-4">
                            <div className="flex items-center justify-between text-sm font-semibold text-depth-secondary">
                                <span>Sumber kredensial</span>
                                <span className="text-depth-primary">
                                    {credential?.source === "database" ? "Database" : ".env"}
                                </span>
                            </div>

                            <div className="flex items-center justify-between text-sm font-semibold text-depth-secondary">
                                <span>API key tersimpan</span>
                                <span className="text-depth-primary">{credential?.api_key_masked ?? "-"}</span>
                            </div>

                            <label className="block">
                                <span className="text-depth-primary font-semibold">Base URL</span>
                                <input
                                    type="url"
                                    value={baseUrl}
                                    onChange={(event) => setBaseUrl(event.target.value)}
                                    placeholder="https://router.example.com"
                                    className="mt-1 w-full rounded-depth-md border border-depth-input bg-depth-input px-4 py-2 text-sm text-depth-primary outline-none focus:border-[var(--depth-color-primary)]"
                                />
                            </label>

                            <label className="block">
                                <span className="text-depth-primary font-semibold">API key</span>
                                <input
                                    type="password"
                                    value={apiKey}
                                    onChange={(event) => setApiKey(event.target.value)}
                                    placeholder="Masukkan API key provider"
                                    autoComplete="off"
                                    className="mt-1 w-full rounded-depth-md border border-depth-input bg-depth-input px-4 py-2 text-sm text-depth-primary outline-none focus:border-[var(--depth-color-primary)]"
                                />
                                <span className="mt-1 block text-xs text-depth-secondary">
                                    Key disimpan terenkripsi dan tidak akan ditampilkan kembali.
                                </span>
                            </label>

                            <label className="block">
                                <span className="text-depth-primary font-semibold">Alasan perubahan</span>
                                <textarea
                                    value={reason}
                                    onChange={(event) => setReason(event.target.value)}
                                    rows={2}
                                    placeholder="Minimal 10 karakter, tercatat di audit log"
                                    className="mt-1 w-full rounded-depth-md border border-depth-input bg-depth-input px-4 py-2 text-sm text-depth-primary outline-none focus:border-[var(--depth-color-primary)]"
                                />
                            </label>

                            {connectionResult && (
                                <div
                                    className={`rounded-depth-md px-4 py-2 text-sm font-semibold ${
                                        connectionResult.reachable
                                            ? "bg-emerald-100 text-emerald-800"
                                            : "bg-red-100 text-red-800"
                                    }`}
                                >
                                    {connectionResult.reachable
                                        ? `Terhubung${connectionResult.models !== null ? `, ${connectionResult.models} model tersedia` : ""}.`
                                        : "Tidak dapat terhubung ke provider."}
                                </div>
                            )}
                        </div>

                        <div className="mt-4 flex gap-2">
                            <button
                                onClick={() => testMutation.mutate()}
                                disabled={isBusy || baseUrl.trim() === "" || apiKey.trim() === ""}
                                className="w-full rounded-depth-md border border-[var(--depth-color-primary)] px-5 py-2 text-sm font-semibold text-[var(--depth-color-primary)] transition hover:-translate-y-0.5 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {testMutation.isPending ? "Menguji..." : "Test Koneksi"}
                            </button>
                            <button
                                onClick={() => saveMutation.mutate()}
                                disabled={isBusy || !canSave}
                                className="w-full rounded-depth-md bg-[var(--depth-color-primary)] px-5 py-2 text-sm font-semibold text-white shadow-depth-sm transition hover:-translate-y-0.5 hover:shadow-depth-md disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {saveMutation.isPending ? "Menyimpan..." : "Simpan"}
                            </button>
                        </div>
                    </>
                )}
            </div>
        </ModalOverlay>
    );
}
