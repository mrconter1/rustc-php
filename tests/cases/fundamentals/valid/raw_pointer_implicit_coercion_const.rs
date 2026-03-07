// exit: 0
// stdout: 1
fn main() {
    let i: i32 = 1;
    let p_imm: *const i32 = &i;
    let v = *p_imm;
    println!("{}", v);
}
