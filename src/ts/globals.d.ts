interface EGBackupData {
    ajaxUrl: string;
    nonce: string;
    downloadNonce?: string;
    [key: string]: any;
}

declare const egBackupData: EGBackupData;
