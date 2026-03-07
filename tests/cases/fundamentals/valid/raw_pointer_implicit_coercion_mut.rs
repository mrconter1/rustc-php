// exit: 0
// stdout: 3
fn main() {
    let mut m: i32 = 2;
    let p_mut: *mut i32 = &mut m;
    *p_mut = 3;
    println!("{}", m);
}
